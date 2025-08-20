# Terminal Registration Guide for POS Providers

## Overview

This guide explains how POS providers can register their terminals with the TSMS system. Terminal registration is a prerequisite for sending transaction data to the TSMS platform, as all transactions must be authenticated using terminal-specific JWT tokens.

## Registration Process

### Prerequisites

Before registering a terminal, you need:

1. **POS Provider Account**: Your organization must be registered as a POS provider in the TSMS system
2. **API Key**: A valid provider API key (provided during provider onboarding)
3. **Terminal Information**: Including terminal unique identifier, hardware ID, and machine number

### Step 1: Collect Terminal Information

Prepare the following terminal details:

-   **Terminal UID**: A unique identifier for the terminal (format: `<provider-code>-<unique-id>`)
-   **Hardware ID**: Physical identifier of the terminal hardware
-   **Machine Number**: The registered machine number (if applicable)
-   **Store Location**: Details about where the terminal is deployed
-   **Tenant Information**: The tenant ID or code that owns this terminal

### Step 2: Submit Terminal Registration Request

Send a POST request to the registration endpoint:
