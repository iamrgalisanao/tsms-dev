<?php

namespace App\Services\Licensing;

use RuntimeException;
use Throwable;

class LicenseReplacementService
{
    public function __construct(
        private readonly SignedLicenseReader $reader,
    ) {
    }

    public function validateAndReplace(string $candidatePath): LicenseReplacementResult
    {
        $readResult = $this->reader->read(
            $candidatePath,
            (string) config('license.paths.public_key')
        );

        if (!$readResult->valid || $readResult->license === null) {
            return LicenseReplacementResult::rejected($readResult->reasonCode);
        }

        $targetPath = (string) config('license.paths.license_file');
        if (trim($targetPath) === '') {
            return LicenseReplacementResult::rejected(LicenseReasonCode::LicenseValidationException);
        }

        try {
            $this->replaceAtomically($candidatePath, $targetPath);
        } catch (Throwable) {
            return LicenseReplacementResult::rejected(LicenseReasonCode::LicenseValidationException);
        }

        return LicenseReplacementResult::replaced($readResult->license, $targetPath);
    }

    private function replaceAtomically(string $sourcePath, string $targetPath): void
    {
        $targetDirectory = dirname($targetPath);
        if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0750, true) && !is_dir($targetDirectory)) {
            throw new RuntimeException('Unable to create license directory.');
        }

        if (!is_readable($sourcePath)) {
            throw new RuntimeException('Candidate license is not readable.');
        }

        $temporaryPath = $targetPath . '.tmp.' . bin2hex(random_bytes(8));
        if (!copy($sourcePath, $temporaryPath)) {
            throw new RuntimeException('Unable to stage replacement license.');
        }

        @chmod($temporaryPath, 0640);

        if (!rename($temporaryPath, $targetPath)) {
            @unlink($temporaryPath);
            throw new RuntimeException('Unable to activate replacement license.');
        }
    }
}
