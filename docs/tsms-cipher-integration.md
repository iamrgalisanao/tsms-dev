# TSMS Cipher Memory Integration

## Overview

This document describes the Cipher memory layer integration for the TSMS (Transaction Management System) project. Cipher provides AI agents with persistent, searchable memory of your project's architecture, documentation, and codebase.

## 🚀 Quick Start

### 1. Install Cipher CLI

```bash
# Using npm (globally)
npm install -g @byterover/cipher

# Verify installation
cipher --version
```

### 2. Configure API Keys

Edit `memAgent/.env` with your OpenAI API key:

```bash
# Copy the example file
cp memAgent/.env.example memAgent/.env

# Edit with your API key
nano memAgent/.env
# Add: OPENAI_API_KEY=your_actual_api_key_here
```

### 3. Load TSMS Knowledge

```bash
# Load all TSMS project documentation
./scripts/tsms-cipher-memory.sh --load-bundle --verbose
```

### 4. Start Memory Agent

```bash
# Start MCP server for VS Code integration
./scripts/tsms-cipher-memory.sh --enable-memory --cipher-mode mcp --verbose
```

## 📁 Project Structure

```
tsms-dev/
├── memAgent/                    # Cipher memory agent configuration
│   ├── cipher.yml              # Main configuration (TSMS optimized)
│   ├── .env                    # Environment variables (API keys)
│   ├── .env.example            # Template for environment setup
│   ├── data/                   # Vector store database
│   └── logs/                   # Cipher operational logs
├── scripts/
│   └── tsms-cipher-memory.sh   # Automation script for TSMS
└── .vscode/
    └── tasks.json              # VS Code tasks for Cipher operations
```

## 🎯 TSMS-Specific Configuration

### Data Sources

The Cipher configuration is optimized for TSMS project knowledge:

1. **Team Bundle**: `web-bundles/teams/team-fullstack.txt` (Primary knowledge base)
2. **Architecture**: `ARCHITECTURE.md` (System design)
3. **Documentation**: `_md/` directory (Comprehensive project docs)
4. **Project Docs**: `docs/` directory (User guides)
5. **Integration Specs**: `app/reference/` (API specifications)
6. **Configuration**: Root level config files

### Context Tags

Memory searches are enhanced with TSMS-specific tags:
- `pos-integration`
- `transaction-processing`
- `laravel-framework`
- `react-frontend`
- `jwt-authentication`
- `api-design`
- `database-schema`
- `testing-procedures`
- `deployment-operations`
- `security-implementation`

## 🛠️ Usage Methods

### 1. Command Line Interface

```bash
# Search for specific knowledge
cipher --agent memAgent/cipher.yml --mode cli --action search --query "POS integration patterns"

# Load new documentation
cipher --agent memAgent/cipher.yml --mode cli --action load --file "path/to/new-docs.md"

# Check memory status
cipher --agent memAgent/cipher.yml --mode cli --action status
```

### 2. VS Code Integration (Recommended)

Use VS Code Command Palette (`Cmd+Shift+P`) and run:
- **Tasks: Run Task** → **TSMS: Load Project Knowledge into Cipher Memory**
- **Tasks: Run Task** → **TSMS: Start Cipher Memory Agent (MCP Mode)**

### 3. Automation Script

```bash
# Load knowledge and start MCP server
./scripts/tsms-cipher-memory.sh --load-bundle --enable-memory --cipher-mode mcp

# Just load knowledge
./scripts/tsms-cipher-memory.sh --load-bundle

# Start different server modes
./scripts/tsms-cipher-memory.sh --enable-memory --cipher-mode api    # HTTP API
./scripts/tsms-cipher-memory.sh --enable-memory --cipher-mode ui     # Web Interface
./scripts/tsms-cipher-memory.sh --enable-memory --cipher-mode mcp    # VS Code MCP
```

### 4. Web Interface

Access the web UI at `http://localhost:3333` when running in UI mode.

## 🔧 Development Workflow

### Daily Development

1. **Morning Setup**:
   ```bash
   ./scripts/tsms-cipher-memory.sh --enable-memory --cipher-mode mcp
   ```

2. **IDE Integration**: Connect your AI coding assistant to MCP server at `localhost:3333`

3. **Query Examples**:
   - "How does TSMS handle POS transaction validation?"
   - "What are the authentication patterns used in the API?"
   - "Show me the database schema for terminal registration"
   - "What testing procedures exist for transaction processing?"

### Knowledge Updates

When you add new documentation or make architectural changes:

```bash
# Refresh the knowledge base
./scripts/tsms-cipher-memory.sh --load-bundle --verbose
```

### Troubleshooting

```bash
# Check status
./scripts/tsms-cipher-memory.sh --verbose

# Force reinstall Cipher
./scripts/tsms-cipher-memory.sh --force-reinstall

# View logs
tail -f memAgent/logs/tsms_cipher.log
```

## 🔒 Security Considerations

### API Keys
- Never commit `memAgent/.env` to version control
- Use separate API keys for different environments
- Monitor OpenAI API usage to control costs

### Data Privacy
- Local vector store keeps your project data private
- Only metadata sent to OpenAI for embeddings
- Configure exclusions for sensitive files in `cipher.yml`

### Access Control
- Cipher runs locally on your development machine
- MCP/API servers bind to localhost only
- Configure firewall rules if needed for team access

## 💡 Advanced Configuration

### Custom Memory Optimization

Edit `memAgent/cipher.yml` to tune for your workflow:

```yaml
memory:
    max_tokens: 16000      # Increase for larger context
    chunk_size: 2000       # Larger chunks for comprehensive docs
    similarity_threshold: 0.8  # Higher precision for exact matches
    max_results: 20        # More comprehensive search results
```

### Additional Data Sources

Add custom data sources:

```yaml
data_sources:
    # Your custom documentation
    - type: "directory"
      path: "../custom-docs/"
      patterns: ["*.md", "*.txt"]
      enabled: true
      weight: 0.9
    
    # External API documentation
    - type: "api"
      endpoint: "https://api.example.com/docs"
      headers:
          Authorization: "Bearer your-token"
      enabled: true
      weight: 0.7
```

### Multi-Agent Setup

Create specialized agents for different contexts:

```bash
# Create documentation-focused agent
cp memAgent/cipher.yml memAgent/cipher-docs.yml
# Edit to focus on documentation only

# Create code-focused agent  
cp memAgent/cipher.yml memAgent/cipher-code.yml
# Edit to focus on source code patterns
```

## 🤝 Team Collaboration

### Shared Knowledge Base

For team environments, consider:

1. **Shared Vector Store**: Store vector database on shared drive
2. **API Mode**: Run Cipher in API mode on shared development server
3. **Documentation Standards**: Maintain consistent documentation for better memory quality

### Best Practices

1. **Regular Updates**: Refresh memory when documentation changes
2. **Consistent Terminology**: Use consistent terms across documentation
3. **Structured Documentation**: Maintain clear headers and sections
4. **Context Tags**: Use TSMS-specific tags in queries

## 📊 Monitoring & Analytics

### Performance Monitoring

```bash
# Check memory usage
du -sh memAgent/data/

# Monitor API calls
grep "API" memAgent/logs/tsms_cipher.log

# Check search performance
grep "search" memAgent/logs/tsms_cipher.log | tail -20
```

### Cost Management

Monitor OpenAI API usage:
- Embedding calls for new content
- LLM calls for query processing
- Set up usage alerts in OpenAI dashboard

## 🚨 Troubleshooting Guide

### Common Issues

#### 1. "Command not found: cipher"
```bash
npm install -g @byterover/cipher
# Or use the script: ./scripts/tsms-cipher-memory.sh --force-reinstall
```

#### 2. "OpenAI API key not configured"
```bash
nano memAgent/.env
# Add your API key: OPENAI_API_KEY=your_key_here
```

#### 3. "Memory search returns no results"
```bash
# Reload the knowledge base
./scripts/tsms-cipher-memory.sh --load-bundle --verbose
```

#### 4. "MCP server not responding"
```bash
# Check if server is running
ps aux | grep cipher

# Restart the server
./scripts/tsms-cipher-memory.sh --enable-memory --cipher-mode mcp
```

### Log Analysis

```bash
# View recent logs
tail -50 memAgent/logs/tsms_cipher.log

# Search for errors
grep -i error memAgent/logs/tsms_cipher.log

# Monitor in real-time
tail -f memAgent/logs/tsms_cipher.log
```

## 🔄 Integration with Existing TSMS Workflow

### Preserving Existing Logic

This Cipher integration:
- ✅ **Preserves all existing code and functionality**
- ✅ **Adds zero runtime dependencies to TSMS**
- ✅ **Runs as separate development tool**
- ✅ **Does not modify any TSMS source files**
- ✅ **Works alongside existing development workflow**

### Laravel Integration

Cipher memory enhances Laravel development:
- Understanding Eloquent model relationships
- API route documentation and patterns
- Middleware and authentication flows
- Database migration strategies
- Queue job processing patterns

### React Integration

Cipher helps with React frontend:
- Component architecture and patterns
- State management approaches
- API integration patterns
- UI/UX design decisions
- Testing strategies

## 📈 Benefits for TSMS Development

### 1. Faster Onboarding
New developers get instant access to project knowledge:
- Architecture decisions and rationale
- POS integration patterns
- Testing procedures
- Deployment processes

### 2. Consistent Development
AI assistants understand TSMS patterns:
- Code style and conventions
- Error handling approaches
- Security implementation patterns
- API design principles

### 3. Knowledge Preservation
Institutional knowledge is preserved:
- Why certain technical decisions were made
- Historical context for changes
- Lessons learned from integrations
- Best practices documentation

### 4. Enhanced Productivity
Developers spend less time searching for information:
- Instant access to relevant documentation
- Context-aware code suggestions
- Architectural guidance
- Testing and deployment procedures

## 🎯 Next Steps

1. **Complete Setup**: Configure your OpenAI API key
2. **Load Knowledge**: Run the bundle loading script
3. **Start Integration**: Begin using MCP mode with VS Code
4. **Team Training**: Share this documentation with your team
5. **Optimize**: Tune the configuration based on your usage patterns

---

**Questions or Issues?** 
- Check the troubleshooting section
- Review the logs in `memAgent/logs/`
- Consult the Cipher documentation: https://github.com/campfirein/cipher
