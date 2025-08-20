# TSMS Cipher Memory Layer Integration - macOS Setup Guide

## Overview

This guide provides step-by-step instructions for integrating the Cipher memory layer with your TSMS (Transaction Management System) development environment on macOS. Cipher is a memory layer for coding agents that uses MCP (Model Context Protocol) to provide persistent, searchable memory for AI assistants.

**✅ Status: IMPLEMENTATION COMPLETE**

The Cipher integration has been fully implemented for the TSMS project with custom optimizations for Laravel/React POS transaction management workflows.

## Prerequisites

-   **macOS**: 12.0 (Monterey) or later
-   **Node.js**: 20.x or higher (⚠️ **Critical**: Cipher v0.2.2+ requires Node 20+)
-   **npm**: Latest version
-   **VS Code**: Latest version
-   **Terminal**: Access to Terminal app or iTerm2
-   **API Keys**: OpenAI API key (required for embeddings and LLM)

## Installation Steps

### 1. Install Cipher CLI Globally

⚠️ **Important**: Cipher v0.2.2+ requires Node.js 20 or higher

```bash
# First, ensure you have Node.js 20+
node --version  # Should show v20.x or higher

# If you need to upgrade Node.js:
# Option 1: Using Homebrew
brew install node@20
brew link node@20

# Option 2: Using nvm (recommended for version management)
brew install nvm
nvm install 20
nvm use 20
nvm alias default 20

# Install Cipher CLI globally
npm install -g @byterover/cipher
```

Verify the installation:

```bash
cipher --version
```

Expected output: `0.2.2` or higher

**Troubleshooting Node Version Issues:**
If you encounter engine compatibility errors:

```bash
# Check current Node version
node --version

# If less than v20.0.0, you must upgrade
nvm install --lts
nvm use --lts
```

### 2. Create Memory Agent Directory Structure

Navigate to your project root and create the memory agent directory:

```bash
cd /path/to/your/project
mkdir -p memAgent
cd memAgent
```

### 3. Create Cipher Configuration

Create the main configuration file:

**`memAgent/cipher.yml`**

```yaml
# Cipher Memory Agent Configuration
name: "your-project-memory-agent"
version: "1.0.0"
description: "Memory layer for your project development"

# LLM Configuration
llm:
    provider: "openai"
    model: "gpt-4o-mini"
    temperature: 0.3
    max_tokens: 4000
    api_key_env: "OPENAI_API_KEY"

# Embedding Configuration
embeddings:
    provider: "openai"
    model: "text-embedding-3-small"
    dimensions: 1536
    api_key_env: "OPENAI_API_KEY"

# Vector Store Configuration
vector_store:
    provider: "local"
    path: "./data/vector_store.db"
    collection: "project_memory"

# Memory Configuration
memory:
    max_tokens: 8000
    overlap_tokens: 200
    chunk_size: 1000
    similarity_threshold: 0.7
    max_results: 10

# Data Sources
data_sources:
    - type: "file"
      path: "../team-fullstack.txt"
      enabled: true
    - type: "directory"
      path: "../"
      patterns: ["*.md", "*.txt", "*.json", "*.yml", "*.yaml"]
      exclude: ["node_modules", ".git", "vendor", "storage/logs"]
      enabled: true

# MCP Configuration
mcp:
    server:
        name: "cipher-memory"
        version: "1.0.0"
    capabilities:
        - "memory_search"
        - "memory_store"
        - "context_retrieval"

# Logging
logging:
    level: "info"
    file: "./logs/cipher.log"

# Server Configuration (for API/UI modes)
server:
    host: "localhost"
    port: 3333
    cors: true
```

### 4. Create Environment Configuration

Create the environment variables file:

**`memAgent/.env`**

```bash
# OpenAI Configuration (Primary)
OPENAI_API_KEY=your_openai_api_key_here
OPENAI_ORG_ID=your_org_id_here

# Anthropic Configuration (Optional)
ANTHROPIC_API_KEY=your_anthropic_api_key_here

# Azure OpenAI Configuration (Optional)
AZURE_OPENAI_API_KEY=your_azure_key_here
AZURE_OPENAI_ENDPOINT=https://your-resource.openai.azure.com/
AZURE_OPENAI_API_VERSION=2024-02-15-preview

# Cipher Configuration
CIPHER_LOG_LEVEL=info
CIPHER_PORT=3333
CIPHER_HOST=localhost

# MCP Configuration
MCP_SERVER_NAME=cipher-memory
MCP_SERVER_VERSION=1.0.0
```

### 5. Update Your Automation Script (if using PowerShell equivalent)

If you're using a shell script for automation, create a bash equivalent:

**`scripts/refresh-memory.sh`**

```bash
#!/bin/bash

# Cipher Memory Integration Script for macOS
# Usage: ./scripts/refresh-memory.sh [options]

set -e

# Default values
ENABLE_MEMORY=false
LOAD_BUNDLE=false
CIPHER_MODE="cli"
BUNDLE_FILE="./team-fullstack.txt"
CONFIG_FILE="./memAgent/cipher.yml"

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
        *)
            echo "Unknown option: $1"
            exit 1
            ;;
    esac
done

# Function to check if cipher is installed
check_cipher_installation() {
    if ! command -v cipher &> /dev/null; then
        echo "❌ Cipher CLI not found. Please install with: npm install -g @byterover/cipher"
        exit 1
    fi
    echo "✅ Cipher CLI found: $(cipher --version)"
}

# Function to execute cipher commands
invoke_cipher() {
    local args=("$@")
    echo "🔄 Executing: cipher ${args[*]}"
    cipher "${args[@]}"
}

# Function to load bundle into memory
load_memory_bundle() {
    if [[ ! -f "$BUNDLE_FILE" ]]; then
        echo "❌ Bundle file not found: $BUNDLE_FILE"
        return 1
    fi

    echo "📚 Loading bundle into Cipher memory..."
    echo "📁 Bundle file: $BUNDLE_FILE"
    echo "⚙️  Config file: $CONFIG_FILE"

    # Load the bundle content
    invoke_cipher --agent "$CONFIG_FILE" --mode cli --action load --file "$BUNDLE_FILE"

    echo "✅ Bundle loaded successfully"
}

# Function to start memory agent
start_memory_agent() {
    echo "🚀 Starting Cipher memory agent in $CIPHER_MODE mode..."

    case $CIPHER_MODE in
        "mcp")
            invoke_cipher --agent "$CONFIG_FILE" --mode mcp &
            echo "🔌 MCP server started in background"
            ;;
        "api")
            invoke_cipher --agent "$CONFIG_FILE" --mode api &
            echo "🌐 API server started in background"
            ;;
        "ui")
            invoke_cipher --agent "$CONFIG_FILE" --mode ui &
            echo "💻 UI server started in background"
            ;;
        *)
            echo "📋 Running in CLI mode"
            invoke_cipher --agent "$CONFIG_FILE" --mode cli
            ;;
    esac
}

# Main execution
main() {
    echo "🧠 Cipher Memory Integration Script"
    echo "=================================="

    check_cipher_installation

    if [[ "$LOAD_BUNDLE" == true ]]; then
        load_memory_bundle
    fi

    if [[ "$ENABLE_MEMORY" == true ]]; then
        start_memory_agent
    fi

    echo "✅ Script completed successfully"
}

# Run main function
main "$@"
```

Make the script executable:

```bash
chmod +x scripts/refresh-memory.sh
```

### 6. Configure VS Code Tasks (Optional)

Create or update your VS Code tasks configuration:

**`.vscode/tasks.json`**

```json
{
    "version": "2.0.0",
    "tasks": [
        {
            "label": "Cipher: Load Bundle into Memory",
            "type": "shell",
            "command": "bash",
            "args": [
                "${workspaceFolder}/scripts/refresh-memory.sh",
                "--load-bundle",
                "--bundle-file",
                "${workspaceFolder}/team-fullstack.txt"
            ],
            "options": {
                "cwd": "${workspaceFolder}"
            },
            "group": "build",
            "presentation": {
                "echo": true,
                "reveal": "always",
                "focus": false,
                "panel": "shared"
            },
            "problemMatcher": []
        },
        {
            "label": "Cipher: Start Memory Agent (MCP Mode)",
            "type": "shell",
            "command": "cipher",
            "args": [
                "--agent",
                "${workspaceFolder}/memAgent/cipher.yml",
                "--mode",
                "mcp"
            ],
            "options": {
                "cwd": "${workspaceFolder}"
            },
            "isBackground": true,
            "group": "build",
            "presentation": {
                "echo": true,
                "reveal": "always",
                "focus": false,
                "panel": "dedicated"
            },
            "problemMatcher": []
        },
        {
            "label": "Cipher: Start Memory Agent (API Mode)",
            "type": "shell",
            "command": "cipher",
            "args": [
                "--agent",
                "${workspaceFolder}/memAgent/cipher.yml",
                "--mode",
                "api",
                "--port",
                "3333"
            ],
            "options": {
                "cwd": "${workspaceFolder}"
            },
            "isBackground": true,
            "group": "build",
            "presentation": {
                "echo": true,
                "reveal": "always",
                "focus": false,
                "panel": "dedicated"
            },
            "problemMatcher": []
        },
        {
            "label": "Cipher: Start Memory Agent (UI Mode)",
            "type": "shell",
            "command": "cipher",
            "args": [
                "--agent",
                "${workspaceFolder}/memAgent/cipher.yml",
                "--mode",
                "ui",
                "--port",
                "3333"
            ],
            "options": {
                "cwd": "${workspaceFolder}"
            },
            "isBackground": true,
            "group": "build",
            "presentation": {
                "echo": true,
                "reveal": "always",
                "focus": false,
                "panel": "dedicated"
            },
            "problemMatcher": []
        }
    ]
}
```

## Usage Guide

### 1. Environment Setup

1. Copy the environment template:

    ```bash
    cp memAgent/.env.example memAgent/.env
    ```

2. Edit `memAgent/.env` and add your OpenAI API key:
    ```bash
    nano memAgent/.env
    ```

### 2. Load Your Project Bundle

Using the script:

```bash
./scripts/refresh-memory.sh --load-bundle
```

Using Cipher CLI directly:

```bash
cipher --agent memAgent/cipher.yml --mode cli --action load --file team-fullstack.txt
```

Using VS Code task:

-   Open Command Palette (`Cmd+Shift+P`)
-   Run "Tasks: Run Task"
-   Select "Cipher: Load Bundle into Memory"

### 3. Start Memory Agent

**MCP Mode (for IDE integration):**

```bash
cipher --agent memAgent/cipher.yml --mode mcp
```

**API Mode (for HTTP access):**

```bash
cipher --agent memAgent/cipher.yml --mode api --port 3333
```

**UI Mode (web interface):**

```bash
cipher --agent memAgent/cipher.yml --mode ui --port 3333
```

### 4. Test Memory Integration

Test memory search:

```bash
cipher --agent memAgent/cipher.yml --mode cli --action search --query "your search term"
```

Test memory storage:

```bash
cipher --agent memAgent/cipher.yml --mode cli --action store --content "Test memory content"
```

## Troubleshooting

### Common Issues

**1. Command not found: cipher**

```bash
# Check if cipher is installed globally
npm list -g @byterover/cipher

# If not installed, run:
npm install -g @byterover/cipher
```

**2. Permission denied on scripts**

```bash
# Make script executable
chmod +x scripts/refresh-memory.sh
```

**3. Node.js version warnings**

```bash
# Update Node.js to latest LTS
brew install node

# Or use nvm
nvm install --lts
nvm use --lts
```

**4. OpenAI API key not working**

-   Verify your API key is valid
-   Check your OpenAI account has sufficient credits
-   Ensure the key is properly set in `memAgent/.env`

**5. MCP connection issues**

-   Check if the MCP server is running
-   Verify the port (default: 3333) is available
-   Check VS Code MCP extension compatibility

### Logs and Debugging

Enable debug logging:

```bash
export CIPHER_LOG_LEVEL=debug
cipher --agent memAgent/cipher.yml --mode cli --debug
```

Check log files:

```bash
tail -f memAgent/logs/cipher.log
```

## Integration with IDEs

### VS Code

-   Install MCP-compatible extensions
-   Configure MCP client to connect to `localhost:3333`
-   Use the provided VS Code tasks for automation

### Cursor

-   Configure MCP integration in settings
-   Point to Cipher MCP server endpoint
-   Use memory-enhanced code suggestions

### Claude Code (if supported)

-   Follow MCP setup guidelines
-   Connect to Cipher memory layer
-   Enable context retrieval features

## Advanced Configuration

### Custom Data Sources

Add custom data sources to `cipher.yml`:

```yaml
data_sources:
    - type: "git"
      repository: "https://github.com/your/repo"
      branch: "main"
      enabled: true
    - type: "api"
      endpoint: "https://api.example.com/docs"
      headers:
          Authorization: "Bearer your-token"
      enabled: true
```

### Memory Optimization

Tune memory settings for better performance:

```yaml
memory:
    max_tokens: 12000 # Increase for larger context
    chunk_size: 1500 # Larger chunks for better coherence
    similarity_threshold: 0.8 # Higher threshold for more precise matches
    max_results: 20 # More results for comprehensive context
```

### Multi-Agent Setup

Configure multiple agents for different purposes:

```bash
# Create specialized configurations
cp memAgent/cipher.yml memAgent/cipher-docs.yml
cp memAgent/cipher.yml memAgent/cipher-code.yml

# Customize each configuration for specific use cases
```

## Best Practices

1. **Regular Bundle Updates**: Refresh your memory bundle when project documentation changes
2. **API Key Security**: Never commit your `.env` file with real API keys
3. **Resource Monitoring**: Monitor OpenAI API usage to avoid unexpected costs
4. **Memory Maintenance**: Periodically clean and optimize your vector store
5. **Backup Configuration**: Version control your `cipher.yml` configurations
6. **Performance Tuning**: Adjust chunk sizes and similarity thresholds based on your use case

## Support and Resources

-   **Cipher GitHub**: https://github.com/campfirein/cipher
-   **MCP Documentation**: https://modelcontextprotocol.io/docs
-   **OpenAI API Docs**: https://platform.openai.com/docs

---

_This documentation was created for Cipher v0.2.2 and may need updates for future versions._
