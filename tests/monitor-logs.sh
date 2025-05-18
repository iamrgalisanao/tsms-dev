#!/bin/bash

# Colors for different log levels
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m'

LOG_FILE="storage/logs/laravel.log"

# Check if log file exists
if [ ! -f "$LOG_FILE" ]; then
    echo -e "${RED}Error: Log file $LOG_FILE does not exist${NC}"
    exit 1
fi

echo -e "${CYAN}Monitoring parser logs... (Ctrl+C to stop)${NC}"
echo -e "${BLUE}Filtering for: TransformTextFormat, Parser test, text format${NC}\n"

# Watch the Laravel log file with improved filtering
tail -f "$LOG_FILE" | while read line; do
    if [[ $line =~ (TransformTextFormat|Parser\ test|text\ format|Content-Type) ]]; then
        # Extract timestamp if present
        timestamp=$(echo "$line" | grep -o '\[[0-9-]* [0-9:]*\]' || echo '')
        
        # Highlight different log levels
        if [[ $line == *"INFO"* ]]; then
            echo -e "${GREEN}$timestamp${NC} ${line#*]}"
        elif [[ $line == *"WARNING"* ]]; then
            echo -e "${YELLOW}$timestamp${NC} ${line#*]}"
        elif [[ $line == *"ERROR"* ]]; then
            echo -e "${RED}$timestamp${NC} ${line#*]}"
        else
            echo -e "${BLUE}$timestamp${NC} ${line#*]}"
        fi
    fi
done
