<?php

namespace App\Services\Licensing;

use JsonException;
use Throwable;

class SignedLicenseReader
{
    public function read(?string $licensePath = null, ?string $publicKeyPath = null): LicenseReadResult
    {
        $licensePath ??= (string) config('license.paths.license_file');
        $publicKeyPath ??= (string) config('license.paths.public_key');

        try {
            if ($licensePath === '' || !file_exists($licensePath)) {
                return LicenseReadResult::invalid(LicenseReasonCode::LicenseFileMissing);
            }

            if (!is_readable($licensePath)) {
                return LicenseReadResult::invalid(LicenseReasonCode::LicenseFileUnreadable);
            }

            $rawLicense = file_get_contents($licensePath);
            if ($rawLicense === false) {
                return LicenseReadResult::invalid(LicenseReasonCode::LicenseFileUnreadable);
            }

            $payload = json_decode($rawLicense, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($payload)) {
                return LicenseReadResult::invalid(LicenseReasonCode::LicenseSchemaInvalid, [
                    'license_file' => ['License payload must be a JSON object.'],
                ]);
            }

            $schemaErrors = $this->validateSchema($payload);
            if ($schemaErrors !== []) {
                return LicenseReadResult::invalid(LicenseReasonCode::LicenseSchemaInvalid, $schemaErrors);
            }

            $license = SignedLicense::fromPayload($payload);
            $signatureResult = $this->verifySignature($license, $publicKeyPath);
            if ($signatureResult !== LicenseReasonCode::LicenseValid) {
                return LicenseReadResult::invalid($signatureResult);
            }

            return LicenseReadResult::valid($license);
        } catch (JsonException) {
            return LicenseReadResult::invalid(LicenseReasonCode::LicenseSchemaInvalid, [
                'license_file' => ['License file must contain valid JSON.'],
            ]);
        } catch (Throwable) {
            return LicenseReadResult::invalid(LicenseReasonCode::LicenseValidationException);
        }
    }

    public function canonicalize(array $payload): string
    {
        $normalized = $this->sortKeysRecursively($payload);

        return json_encode(
            $normalized,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }

    private function validateSchema(array $payload): array
    {
        $errors = [];
        $requiredStrings = [
            'license_version',
            'license_id',
            'client_id',
            'environment',
            'deployment_id',
            'signature_algorithm',
            'signature',
        ];

        foreach ($requiredStrings as $field) {
            if (!isset($payload[$field]) || !is_string($payload[$field]) || trim($payload[$field]) === '') {
                $errors[$field][] = 'This field is required and must be a non-empty string.';
            }
        }

        if (!isset($payload['licensed_location_codes']) || !is_array($payload['licensed_location_codes'])) {
            $errors['licensed_location_codes'][] = 'This field is required and must be an array.';
        } else {
            foreach ($payload['licensed_location_codes'] as $index => $locationCode) {
                if (!is_string($locationCode) || trim($locationCode) === '') {
                    $errors["licensed_location_codes.$index"][] = 'Location codes must be non-empty strings.';
                }
            }
        }

        foreach (['not_before', 'expires_at'] as $dateField) {
            if (isset($payload[$dateField]) && (!is_string($payload[$dateField]) || strtotime($payload[$dateField]) === false)) {
                $errors[$dateField][] = 'This field must be a valid date/time string.';
            }
        }

        if (isset($payload['server_fingerprint_hash']) && (!is_string($payload['server_fingerprint_hash']) || trim($payload['server_fingerprint_hash']) === '')) {
            $errors['server_fingerprint_hash'][] = 'This field must be a non-empty string when present.';
        }

        return $errors;
    }

    private function verifySignature(SignedLicense $license, string $publicKeyPath): LicenseReasonCode
    {
        if ($publicKeyPath === '' || !is_readable($publicKeyPath)) {
            return LicenseReasonCode::LicenseFileUnreadable;
        }

        $publicKey = file_get_contents($publicKeyPath);
        if ($publicKey === false || trim($publicKey) === '') {
            return LicenseReasonCode::LicenseFileUnreadable;
        }

        return match (strtolower($license->signatureAlgorithm)) {
            'ed25519' => $this->verifyEd25519($license, trim($publicKey)),
            default => LicenseReasonCode::LicenseUnsupportedSignatureAlgorithm,
        };
    }

    private function verifyEd25519(SignedLicense $license, string $publicKey): LicenseReasonCode
    {
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            return LicenseReasonCode::LicenseUnsupportedSignatureAlgorithm;
        }

        $signature = base64_decode($license->signature, true);
        $decodedPublicKey = base64_decode($publicKey, true);

        if ($signature === false || $decodedPublicKey === false) {
            return LicenseReasonCode::LicenseSignatureInvalid;
        }

        $isValid = sodium_crypto_sign_verify_detached(
            $signature,
            $this->canonicalize($license->unsignedPayload()),
            $decodedPublicKey
        );

        return $isValid
            ? LicenseReasonCode::LicenseValid
            : LicenseReasonCode::LicenseSignatureInvalid;
    }

    private function sortKeysRecursively(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (!array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->sortKeysRecursively($item);
        }

        return $value;
    }
}
