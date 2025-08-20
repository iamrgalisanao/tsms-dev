#!/bin/bash

# TSMS Cipher Memory Integration Script for macOS
# Optimized for Laravel/React POS Transaction Management System
# Usage: ./scripts/tsms-cipher-memory.sh [options]

set -e

# Color output for better UX
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
PURPLE='\033[0;35m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

# Default values
ENABLE_MEMORY=false
LOAD_BUNDLE=false
CIPHER_MODE="cli"
BUNDLE_FILE="./web-bundles/teams/team-fullstack.txt"
CONFIG_FILE="./memAgent/cipher.yml"
ENVIRONMENT_FILE="./memAgent/.env"
FORCE_REINSTALL=false
VERBOSE=false

# Parse arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        --enable-memory)
            ENABLE_MEMORY=true
            shift
            ;;
        --load-bundle)
            LOAD_BUNDLE=true
            shift
            ;;
        --cipher-mode)
            CIPHER_MODE="$2"
            shift 2
            ;;
        --bundle-file)
            BUNDLE_FILE="$2"
            shift 2
            ;;
        --force-reinstall)
            FORCE_REINSTALL=true
            shift
            ;;
        --verbose|-v)
            VERBOSE=true
            shift
            ;;
        --help|-h)
            show_help
            exit 0
            ;;
        *)
            echo -e "${RED}Unknown option: $1${NC}"
            echo "Use --help for usage information"
            exit 1
            ;;
    esac
done

# Help function
show_help() {
    echo -e "${CYAN}TSMS Cipher Memory Integration Script${NC}"
    echo ""
    echo "Usage: $0 [OPTIONS]"
    echo ""
    echo "Options:"
    echo "  --enable-memory       Start the Cipher memory agent"
    echo "  --load-bundle         Load project documentation into memory"
    echo "  --cipher-mode MODE    Set mode: cli, mcp, api, ui (default: cli)"
    echo "  --bundle-file FILE    Specify bundle file path"
    echo "  --force-reinstall     Force reinstall Cipher CLI"
    echo "  --verbose, -v         Enable verbose output"
    echo "  --help, -h            Show this help message"
    echo ""
    echo "Examples:"
    echo "  $0 --load-bundle                    # Load TSMS docs into memory"
    echo "  $0 --enable-memory --cipher-mode mcp # Start MCP server"
    echo "  $0 --load-bundle --enable-memory    # Load docs and start agent"
}

# Logging function
log() {
    local level=$1
    shift
    local message="$*"
    local timestamp=$(date '+%Y-%m-%d %H:%M:%S')
    
    case $level in
        "INFO")
            echo -e "${GREEN}[${timestamp}] ℹ️  ${message}${NC}"
            ;;
        "WARN")
            echo -e "${YELLOW}[${timestamp}] ⚠️  ${message}${NC}"
            ;;
        "ERROR")
            echo -e "${RED}[${timestamp}] ❌ ${message}${NC}"
            ;;
        "SUCCESS")
            echo -e "${GREEN}[${timestamp}] ✅ ${message}${NC}"
            ;;
        "DEBUG")
            if [[ "$VERBOSE" == true ]]; then
                echo -e "${PURPLE}[${timestamp}] 🔍 ${message}${NC}"
            fi
            ;;
    esac
}

# Function to check system requirements
check_system_requirements() {
    log "INFO" "Checking system requirements for TSMS Cipher integration..."
    
    # Check macOS version
    local macos_version=$(sw_vers -productVersion)
    log "DEBUG" "macOS version: $macos_version"
    
    # Check Node.js version
    if ! command -v node &> /dev/null; then
        log "ERROR" "Node.js is not installed. Please install Node.js 20+ first."
        echo -e "${CYAN}Install with: brew install node${NC}"
        exit 1
    fi
    
    local node_version=$(node --version | sed 's/v//')
    local node_major=$(echo $node_version | cut -d. -f1)
    
    if [ "$node_major" -lt 20 ]; then
        log "ERROR" "Node.js version $node_version is too old. Cipher requires Node.js 20+"
        echo -e "${CYAN}Upgrade with: brew install node@20 && brew link node@20${NC}"
        exit 1
    fi
    
    log "SUCCESS" "Node.js version $node_version is compatible"
    
    # Check npm
    if ! command -v npm &> /dev/null; then
        log "ERROR" "npm is not available"
        exit 1
    fi
    
    local npm_version=$(npm --version)
    log "SUCCESS" "npm version $npm_version is available"
}

# Function to check if cipher is installed
check_cipher_installation() {
    log "INFO" "Checking Cipher CLI installation..."
    
    if ! command -v cipher &> /dev/null; then
        log "WARN" "Cipher CLI not found. Installing..."
        install_cipher
        return
    fi
    
    local cipher_version=$(cipher --version 2>/dev/null || echo "unknown")
    log "SUCCESS" "Cipher CLI found: version $cipher_version"
    
    if [[ "$FORCE_REINSTALL" == true ]]; then
        log "INFO" "Force reinstall requested, updating Cipher CLI..."
        install_cipher
    fi
}

# Function to install Cipher CLI
install_cipher() {
    log "INFO" "Installing Cipher CLI globally..."
    
    if npm install -g @byterover/cipher; then
        local cipher_version=$(cipher --version 2>/dev/null || echo "unknown")
        log "SUCCESS" "Cipher CLI installed successfully: version $cipher_version"
    else
        log "ERROR" "Failed to install Cipher CLI"
        exit 1
    fi
}

# Function to load environment variables from .env file
load_environment() {
    log "DEBUG" "Loading environment variables from $ENVIRONMENT_FILE"
    
    if [[ -f "$ENVIRONMENT_FILE" ]]; then
        # Export variables from .env file
        while IFS= read -r line || [[ -n "$line" ]]; do
            # Skip empty lines and comments
            if [[ -n "$line" && ! "$line" =~ ^[[:space:]]*# ]]; then
                # Remove quotes and export
                line=$(echo "$line" | sed 's/^"//' | sed 's/"$//')
                export "$line"
                
                # Log without revealing sensitive values
                var_name=$(echo "$line" | cut -d'=' -f1)
                if [[ "$var_name" == *"KEY"* ]] || [[ "$var_name" == *"SECRET"* ]]; then
                    log "DEBUG" "Exported $var_name=***"
                else
                    log "DEBUG" "Exported $line"
                fi
            fi
        done < "$ENVIRONMENT_FILE"
        
        log "SUCCESS" "Environment variables loaded from $ENVIRONMENT_FILE"
    else
        log "WARN" "Environment file not found: $ENVIRONMENT_FILE"
    fi
}

# Function to validate environment setup
validate_environment() {
    log "INFO" "Validating TSMS project environment..."
    
    # Check if we're in the right directory
    if [[ ! -f "composer.json" ]] || [[ ! -f "package.json" ]]; then
        log "ERROR" "This doesn't appear to be the TSMS project root directory"
        log "ERROR" "Please run this script from the TSMS project root"
        exit 1
    fi
    
    # Check for Laravel
    if ! grep -q "laravel/framework" composer.json; then
        log "WARN" "Laravel framework not detected in composer.json"
    else
        log "SUCCESS" "Laravel framework detected"
    fi
    
    # Check for React
    if ! grep -q "react" package.json; then
        log "WARN" "React not detected in package.json"
    else
        log "SUCCESS" "React framework detected"
    fi
    
    # Check for bundle file
    if [[ ! -f "$BUNDLE_FILE" ]]; then
        log "WARN" "Bundle file not found: $BUNDLE_FILE"
        log "INFO" "Will use directory-based indexing instead"
    else
        log "SUCCESS" "Team bundle file found: $BUNDLE_FILE"
    fi
    
    # Check environment file
    if [[ ! -f "$ENVIRONMENT_FILE" ]]; then
        log "WARN" "Environment file not found: $ENVIRONMENT_FILE"
        log "INFO" "Please configure your OpenAI API key in $ENVIRONMENT_FILE"
    else
        log "SUCCESS" "Environment configuration found"
        
        # Check for API key (without revealing it)
        if grep -q "OPENAI_API_KEY=" "$ENVIRONMENT_FILE" && ! grep -q "OPENAI_API_KEY=$" "$ENVIRONMENT_FILE"; then
            log "SUCCESS" "OpenAI API key appears to be configured"
        else
            log "WARN" "OpenAI API key not configured in $ENVIRONMENT_FILE"
            log "INFO" "Please add your OpenAI API key to continue"
        fi
    fi
}

# Function to execute cipher commands with error handling
invoke_cipher() {
    local args=("$@")
    log "DEBUG" "Executing: cipher ${args[*]}"
    
    if cipher "${args[@]}"; then
        return 0
    else
        local exit_code=$?
        log "ERROR" "Cipher command failed with exit code $exit_code"
        return $exit_code
    fi
}

# Function to load bundle into memory
load_memory_bundle() {
    log "INFO" "Loading TSMS project documentation into Cipher memory..."
    log "DEBUG" "Config file: $CONFIG_FILE"
    
    if [[ -f "$BUNDLE_FILE" ]]; then
        log "INFO" "Loading team bundle: $BUNDLE_FILE"
        log "INFO" "Starting Cipher CLI with team bundle content..."
        if invoke_cipher --agent "$CONFIG_FILE" --mode cli "Load the contents of this file: $BUNDLE_FILE"; then
            log "SUCCESS" "Team bundle loading initiated"
        else
            log "ERROR" "Failed to initiate team bundle loading"
            return 1
        fi
    fi
    
    # Start Cipher to index and process the documentation
    log "INFO" "Initializing TSMS project knowledge indexing..."
    log "INFO" "This will process all configured data sources from cipher.yml..."
    if invoke_cipher --agent "$CONFIG_FILE" --mode cli "Index all configured data sources and prepare the memory system for TSMS project queries"; then
        log "SUCCESS" "TSMS documentation indexing initiated"
    else
        log "ERROR" "Failed to initialize documentation indexing"
        return 1
    fi
    
    log "SUCCESS" "TSMS project knowledge loading process completed"
}

# Function to start memory agent
start_memory_agent() {
    log "INFO" "Starting TSMS Cipher memory agent in $CIPHER_MODE mode..."
    
    case $CIPHER_MODE in
        "mcp")
            log "INFO" "Starting MCP server for VS Code integration..."
            invoke_cipher --agent "$CONFIG_FILE" --mode mcp --port 3333 &
            local pid=$!
            echo $pid > ./memAgent/cipher_mcp.pid
            log "SUCCESS" "MCP server started in background (PID: $pid)"
            log "INFO" "Connect your VS Code MCP client to localhost:3333"
            ;;
        "api")
            log "INFO" "Starting API server for HTTP access..."
            invoke_cipher --agent "$CONFIG_FILE" --mode api --port 3333 &
            local pid=$!
            echo $pid > ./memAgent/cipher_api.pid
            log "SUCCESS" "API server started in background (PID: $pid)"
            log "INFO" "Access API at http://localhost:3333"
            ;;
        "ui")
            log "INFO" "Starting UI server for web interface..."
            invoke_cipher --agent "$CONFIG_FILE" --mode ui --port 3333 &
            local pid=$!
            echo $pid > ./memAgent/cipher_ui.pid
            log "SUCCESS" "UI server started in background (PID: $pid)"
            log "INFO" "Access web interface at http://localhost:3333"
            ;;
        "cli")
            log "INFO" "Running in CLI mode for testing..."
            invoke_cipher --agent "$CONFIG_FILE" --mode cli "What is the TSMS project about?"
            log "SUCCESS" "CLI mode test completed"
            ;;
        *)
            log "ERROR" "Unknown cipher mode: $CIPHER_MODE"
            log "INFO" "Valid modes: cli, mcp, api, ui"
            exit 1
            ;;
    esac
}

# Function to show status
show_status() {
    log "INFO" "TSMS Cipher Memory Agent Status"
    echo -e "${CYAN}================================${NC}"
    
    # Check running processes
    local mcp_pid_file="./memAgent/cipher_mcp.pid"
    local api_pid_file="./memAgent/cipher_api.pid"
    local ui_pid_file="./memAgent/cipher_ui.pid"
    
    if [[ -f "$mcp_pid_file" ]]; then
        local pid=$(cat "$mcp_pid_file")
        if ps -p "$pid" > /dev/null 2>&1; then
            log "SUCCESS" "MCP server running (PID: $pid)"
        else
            log "WARN" "MCP server not running (stale PID file)"
            rm -f "$mcp_pid_file"
        fi
    fi
    
    if [[ -f "$api_pid_file" ]]; then
        local pid=$(cat "$api_pid_file")
        if ps -p "$pid" > /dev/null 2>&1; then
            log "SUCCESS" "API server running (PID: $pid)"
        else
            log "WARN" "API server not running (stale PID file)"
            rm -f "$api_pid_file"
        fi
    fi
    
    if [[ -f "$ui_pid_file" ]]; then
        local pid=$(cat "$ui_pid_file")
        if ps -p "$pid" > /dev/null 2>&1; then
            log "SUCCESS" "UI server running (PID: $pid)"
        else
            log "WARN" "UI server not running (stale PID file)"
            rm -f "$ui_pid_file"
        fi
    fi
}

# Main execution function
main() {
    echo -e "${PURPLE}"
    echo "🧠 TSMS Cipher Memory Integration Script"
    echo "==========================================="
    echo -e "${NC}"
    
    check_system_requirements
    check_cipher_installation
    load_environment
    validate_environment
    
    if [[ "$LOAD_BUNDLE" == true ]]; then
        load_memory_bundle
    fi
    
    if [[ "$ENABLE_MEMORY" == true ]]; then
        start_memory_agent
    else
        show_status
    fi
    
    echo -e "${CYAN}"
    echo "🎉 TSMS Cipher integration setup complete!"
    echo ""
    echo "Next steps:"
    echo "1. Configure your OpenAI API key in memAgent/.env"
    echo "2. Load your project knowledge: $0 --load-bundle"
    echo "3. Start the memory agent: $0 --enable-memory --cipher-mode mcp"
    echo "4. Connect your IDE to the MCP server at localhost:3333"
    echo -e "${NC}"
}

# Handle script interruption
trap 'log "WARN" "Script interrupted"; exit 130' INT

# Run main function
main "$@"
