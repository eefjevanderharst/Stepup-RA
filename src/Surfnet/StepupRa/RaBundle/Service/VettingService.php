<?php

/**
 * Copyright 2014 SURFnet bv
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace Surfnet\StepupRa\RaBundle\Service;

use Surfnet\StepupBundle\Value\SecondFactorType;
use Surfnet\StepupMiddlewareClientBundle\Identity\Command\VetSecondFactorCommand;
use Surfnet\StepupMiddlewareClientBundle\Service\CommandService;
use Surfnet\StepupRa\RaBundle\Command\SendSmsChallengeCommand;
use Surfnet\StepupRa\RaBundle\Command\StartVettingProcedureCommand;
use Surfnet\StepupRa\RaBundle\Command\VerifyIdentityCommand;
use Surfnet\StepupRa\RaBundle\Command\VerifyPhoneNumberCommand;
use Surfnet\StepupRa\RaBundle\Command\VerifyYubikeyPublicIdCommand;
use Surfnet\StepupRa\RaBundle\Exception\DomainException;
use Surfnet\StepupRa\RaBundle\Exception\InvalidArgumentException;
use Surfnet\StepupRa\RaBundle\Exception\LoaTooLowException;
use Surfnet\StepupRa\RaBundle\Exception\UnknownVettingProcedureException;
use Surfnet\StepupRa\RaBundle\Repository\VettingProcedureRepository;
use Surfnet\StepupRa\RaBundle\Service\Gssf\VerificationResult as GssfVerificationResult;
use Surfnet\StepupRa\RaBundle\Service\YubikeySecondFactor\VerificationResult as YubikeyVerificationResult;
use Surfnet\StepupRa\RaBundle\VettingProcedure;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class VettingService
{
    /**
     * @var SmsSecondFactorService
     */
    private $smsSecondFactorService;

    /**
     * @var YubikeySecondFactorService
     */
    private $yubikeySecondFactorService;

    /**
     * @var GssfService
     */
    private $gssfService;

    /**
     * @var CommandService
     */
    private $commandService;

    /**
     * @var VettingProcedureRepository
     */
    private $vettingProcedureRepository;

    public function __construct(
        SmsSecondFactorService $smsSecondFactorService,
        YubikeySecondFactorService $yubikeySecondFactorService,
        GssfService $gssfService,
        CommandService $commandService,
        VettingProcedureRepository $vettingProcedureRepository
    ) {
        $this->smsSecondFactorService = $smsSecondFactorService;
        $this->yubikeySecondFactorService = $yubikeySecondFactorService;
        $this->gssfService = $gssfService;
        $this->commandService = $commandService;
        $this->vettingProcedureRepository = $vettingProcedureRepository;
    }

    /**
     * @param StartVettingProcedureCommand $command
     * @return bool
     */
    public function isLoaSufficientToStartProcedure(StartVettingProcedureCommand $command)
    {
        $secondFactorType = new SecondFactorType($command->secondFactor->type);

        return $secondFactorType->isSatisfiedBy($command->authorityLoa);
    }

    /**
     * @param StartVettingProcedureCommand $command
     * @return string The procedure ID.
     */
    public function startProcedure(StartVettingProcedureCommand $command)
    {
        if (!$this->isLoaSufficientToStartProcedure($command)) {
            throw new LoaTooLowException(
                sprintf(
                    "Registration authority has LoA '%s', which is not enough to allow vetting of a '%s' second factor",
                    $command->authorityLoa,
                    $command->secondFactor->type
                )
            );
        }

        $procedure = VettingProcedure::start(
            $command->secondFactor->id,
            $command->registrationCode,
            $command->secondFactor
        );

        $this->vettingProcedureRepository->store($procedure);

        return $procedure->getId();
    }

    /**
     * @param string $procedureId
     * @param SendSmsChallengeCommand $command
     * @return bool
     * @throws UnknownVettingProcedureException
     * @throws DomainException
     */
    public function sendSmsChallenge($procedureId, SendSmsChallengeCommand $command)
    {
        $procedure = $this->getProcedure($procedureId);

        $command->phoneNumber = $procedure->getSecondFactor()->secondFactorIdentifier;
        $command->identity = $procedure->getSecondFactor()->identityId;
        $command->institution = $procedure->getSecondFactor()->institution;

        return $this->smsSecondFactorService->sendChallenge($command);
    }

    /**
     * @param $procedureId
     * @param VerifyPhoneNumberCommand $command
     * @return bool
     * @throws UnknownVettingProcedureException
     * @throws DomainException
     */
    public function verifyPhoneNumber($procedureId, VerifyPhoneNumberCommand $command)
    {
        $procedure = $this->getProcedure($procedureId);

        $command->phoneNumber = $procedure->getSecondFactor()->secondFactorIdentifier;

        if (!$this->smsSecondFactorService->verifyPossession($command)) {
            return false;
        }

        $procedure->verifySecondFactorIdentifier($procedure->getSecondFactor()->secondFactorIdentifier);
        $this->vettingProcedureRepository->store($procedure);

        return true;
    }

    /**
     * @param string $procedureId
     * @param VerifyYubikeyPublicIdCommand $command
     * @return YubikeyVerificationResult
     * @throws UnknownVettingProcedureException
     * @throws DomainException
     */
    public function verifyYubikeyPublicId($procedureId, VerifyYubikeyPublicIdCommand $command)
    {
        $procedure = $this->getProcedure($procedureId);

        $command->expectedPublicId = $procedure->getSecondFactor()->secondFactorIdentifier;
        $command->identityId = $procedure->getSecondFactor()->identityId;
        $command->institution = $procedure->getSecondFactor()->institution;

        $result = $this->yubikeySecondFactorService->verifyYubikeyPublicId($command);

        if ($result->didPublicIdMatch()) {
            $procedure->verifySecondFactorIdentifier($result->getPublicId());

            $this->vettingProcedureRepository->store($procedure);
        }

        return $result;
    }

    /**
     * @param string $procedureId
     */
    public function startGssfVerification($procedureId)
    {
        $procedure = $this->getProcedure($procedureId);

        $this->gssfService->startVerification($procedure->getSecondFactor()->secondFactorIdentifier, $procedureId);
    }

    /**
     * @param string $gssfId
     * @return GssfVerificationResult
     */
    public function verifyGssfId($gssfId)
    {
        $result = $this->gssfService->verify($gssfId);

        if (!$result->isSuccess()) {
            return $result;
        }

        $procedure = $this->getProcedure($result->getProcedureId());
        $procedure->verifySecondFactorIdentifier($gssfId);

        $this->vettingProcedureRepository->store($procedure);

        return $result;
    }

    /**
     * @param string $procedureId
     * @param VerifyIdentityCommand $command
     * @return void
     * @throws UnknownVettingProcedureException
     * @throws DomainException
     */
    public function verifyIdentity($procedureId, VerifyIdentityCommand $command)
    {
        $procedure = $this->getProcedure($procedureId);
        $procedure->verifyIdentity($command->documentNumber, $command->identityVerified);

        $this->vettingProcedureRepository->store($procedure);
    }

    /**
     * @param string $procedureId
     * @return bool
     * @throws UnknownVettingProcedureException
     * @throws DomainException
     */
    public function vet($procedureId)
    {
        $procedure = $this->getProcedure($procedureId);
        $procedure->vet();

        $command = new VetSecondFactorCommand();
        $command->identityId = $procedure->getSecondFactor()->identityId;
        $command->registrationCode = $procedure->getRegistrationCode();
        $command->secondFactorIdentifier = $procedure->getInputSecondFactorIdentifier();
        $command->documentNumber = $procedure->getDocumentNumber();
        $command->identityVerified = $procedure->isIdentityVerified();

        $result = $this->commandService->execute($command);

        if (!$result->isSuccessful()) {
            return false;
        }

        $this->vettingProcedureRepository->remove($procedureId);

        return true;
    }

    /**
     * @param string $procedureId
     * @return string
     * @throws UnknownVettingProcedureException
     */
    public function getIdentityCommonName($procedureId)
    {
        return $this->getProcedure($procedureId)->getSecondFactor()->commonName;
    }

    /**
     * @param $procedureId
     * @return string
     * @throws UnknownVettingProcedureException
     */
    public function getSecondFactorIdentifier($procedureId)
    {
        return $this->getProcedure($procedureId)->getSecondFactor()->secondFactorIdentifier;
    }

    /**
     * @param string $procedureId
     * @return null|VettingProcedure
     * @throws UnknownVettingProcedureException
     */
    private function getProcedure($procedureId)
    {
        if (!is_string($procedureId)) {
            throw InvalidArgumentException::invalidType('string', 'procedureId', $procedureId);
        }

        $procedure = $this->vettingProcedureRepository->retrieve($procedureId);

        if (!$procedure) {
            throw new UnknownVettingProcedureException(
                sprintf("No vetting procedure with id '%s' is known.", $procedureId)
            );
        }

        return $procedure;
    }
}
