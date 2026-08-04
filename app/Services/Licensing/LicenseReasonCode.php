<?php

namespace App\Services\Licensing;

enum LicenseReasonCode: string
{
    case LicenseValid = 'LICENSE_VALID';
    case LicenseFileMissing = 'LICENSE_FILE_MISSING';
    case LicenseFileUnreadable = 'LICENSE_FILE_UNREADABLE';
    case LicenseSchemaInvalid = 'LICENSE_SCHEMA_INVALID';
    case LicenseSignatureInvalid = 'LICENSE_SIGNATURE_INVALID';
    case LicenseUnsupportedSignatureAlgorithm = 'LICENSE_UNSUPPORTED_SIGNATURE_ALGORITHM';
    case LicenseNotYetValid = 'LICENSE_NOT_YET_VALID';
    case LicenseExpired = 'LICENSE_EXPIRED';
    case LicenseEnvironmentMismatch = 'LICENSE_ENVIRONMENT_MISMATCH';
    case LicenseDeploymentIdMismatch = 'LICENSE_DEPLOYMENT_ID_MISMATCH';
    case LicenseServerFingerprintMismatch = 'LICENSE_SERVER_FINGERPRINT_MISMATCH';
    case LicenseValidationException = 'LICENSE_VALIDATION_EXCEPTION';
    case ClockRollbackSuspected = 'CLOCK_ROLLBACK_SUSPECTED';
    case CopySuspected = 'COPY_SUSPECTED';
}
