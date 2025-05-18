#!/bin/bash

GREEN='\033[0;32m'
BLUE='\033[0;34m'
RED='\033[0;31m'
NC='\033[0m'

BASE_URL="http://localhost/PITX/TSMS/tsms-dev/public/api/v1/test-parser"

format_json() {
    python -c "import json,sys;print(json.dumps(json.loads(sys.stdin.read()), indent=2))" 2>/dev/null
}

make_request() {
    local test_name=$1
    local data=$2
    
    echo -e "${GREEN}$test_name${NC}"
    echo -e "${BLUE}Request data:${NC}"
    echo "$data"
    echo -e "\n${BLUE}Response:${NC}"
    
    response=$(curl -s -X POST "$BASE_URL" \
        -H "Content-Type: text/plain" \
        -H "Accept: application/json" \
        -H "X-Debug: true" \
        -d "$data")
    
    if [ ! -z "$response" ]; then
        echo "$response" | format_json || {
            echo -e "${RED}Raw response:${NC}"
            echo "$response"
        }
    else
        echo -e "${RED}Empty response received${NC}"
    fi
    echo
}

echo -e "${BLUE}Testing Text Format Parser${NC}\n"

# Test 1: KEY: VALUE format
make_request "Test 1: KEY: VALUE format" "TENANT_ID: STORE123
TRANSACTION_ID: TX789
TRANSACTION_TIMESTAMP: 2024-05-18 14:30:00
GROSS_SALES: 1500.75
NET_SALES: 1340.25
VATABLE_SALES: 1200.00
VAT_EXEMPT_SALES: 140.25
VAT_AMOUNT: 144.00
TRANSACTION_COUNT: 5
PAYLOAD_CHECKSUM: abc123xyz"

sleep 1

# Test 2: KEY=VALUE format
make_request "Test 2: KEY=VALUE format" "TENANTID=STORE123
TXID=TX789
DATETIME=2024-05-18 14:30:00
GROSS=1500.75
NET=1340.25
VATABLESALES=1200.00
VATEXEMPTSALES=140.25
VAT=144.00
TX_COUNT=5
CHECKSUM=abc123xyz"

sleep 1

# Test 3: KEY VALUE format
make_request "Test 3: KEY VALUE format" "TENANT-ID STORE123
TX_ID TX789
TX_TIME 2024-05-18 14:30:00
GROSSSALES 1500.75
NETSALES 1340.25
VAT_SALES 1200.00
EXEMPT_SALES 140.25
VATAMOUNT 144.00
TRANSACTIONCOUNT 5
HASH abc123xyz"

echo -e "${BLUE}Testing completed${NC}"
