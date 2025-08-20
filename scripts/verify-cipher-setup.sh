#!/bin/bash

# TSMS Cipher Integration Setup Verification Script
# This script verifies that the Cipher integration is properly set up

set -e

# Color output
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo -e "${BLUE}🔍 TSMS Cipher Integration Setup Verification${NC}"
echo "================================================"

# Check Node.js version
echo -n "Checking Node.js version... "
if command -v node &> /dev/null; then
    NODE_VERSION=$(node --version)
    NODE_MAJOR=$(echo $NODE_VERSION | sed 's/v//' | cut -d. -f1)
    if [ "$NODE_MAJOR" -ge 20 ]; then
        echo -e "${GREEN}✅ $NODE_VERSION (Compatible)${NC}"
    else
        echo -e "${RED}❌ $NODE_VERSION (Requires 20+)${NC}"
        exit 1
    fi
else
    echo -e "${RED}❌ Not installed${NC}"
    exit 1
fi

# Check Cipher CLI
echo -n "Checking Cipher CLI... "
if command -v cipher &> /dev/null; then
    CIPHER_VERSION=$(cipher --version 2>/dev/null || echo "unknown")
    echo -e "${GREEN}✅ Version $CIPHER_VERSION${NC}"
else
    echo -e "${YELLOW}⚠️  Not installed (run: npm install -g @byterover/cipher)${NC}"
fi

# Check project structure
echo -n "Checking TSMS project structure... "
if [[ -f "composer.json" && -f "package.json" ]]; then
    echo -e "${GREEN}✅ Valid TSMS project${NC}"
else
    echo -e "${RED}❌ Invalid project structure${NC}"
    exit 1
fi

# Check Cipher configuration
echo -n "Checking Cipher configuration... "
if [[ -f "memAgent/cipher.yml" ]]; then
    echo -e "${GREEN}✅ Configuration exists${NC}"
else
    echo -e "${RED}❌ Configuration missing${NC}"
    exit 1
fi

# Check environment file
echo -n "Checking environment configuration... "
if [[ -f "memAgent/.env" ]]; then
    if grep -q "OPENAI_API_KEY=" "memAgent/.env" && ! grep -q "OPENAI_API_KEY=$" "memAgent/.env"; then
        echo -e "${GREEN}✅ API key configured${NC}"
    else
        echo -e "${YELLOW}⚠️  API key not set${NC}"
    fi
else
    echo -e "${YELLOW}⚠️  Environment file missing${NC}"
fi

# Check bundle file
echo -n "Checking team bundle... "
if [[ -f "web-bundles/teams/team-fullstack.txt" ]]; then
    FILE_SIZE=$(wc -c < "web-bundles/teams/team-fullstack.txt")
    if [ "$FILE_SIZE" -gt 1000 ]; then
        echo -e "${GREEN}✅ Bundle exists (${FILE_SIZE} bytes)${NC}"
    else
        echo -e "${YELLOW}⚠️  Bundle too small${NC}"
    fi
else
    echo -e "${YELLOW}⚠️  Bundle file missing${NC}"
fi

# Check script permissions
echo -n "Checking script permissions... "
if [[ -x "scripts/tsms-cipher-memory.sh" ]]; then
    echo -e "${GREEN}✅ Script executable${NC}"
else
    echo -e "${YELLOW}⚠️  Making script executable...${NC}"
    chmod +x "scripts/tsms-cipher-memory.sh"
    echo -e "${GREEN}✅ Fixed${NC}"
fi

# Check VS Code tasks
echo -n "Checking VS Code tasks... "
if [[ -f ".vscode/tasks.json" ]]; then
    echo -e "${GREEN}✅ Tasks configured${NC}"
else
    echo -e "${YELLOW}⚠️  VS Code tasks missing${NC}"
fi

# Check directories
echo -n "Checking Cipher directories... "
MISSING_DIRS=""
for dir in "memAgent/data" "memAgent/logs"; do
    if [[ ! -d "$dir" ]]; then
        MISSING_DIRS="$MISSING_DIRS $dir"
    fi
done

if [[ -z "$MISSING_DIRS" ]]; then
    echo -e "${GREEN}✅ All directories exist${NC}"
else
    echo -e "${YELLOW}⚠️  Creating missing directories...${NC}"
    mkdir -p $MISSING_DIRS
    echo -e "${GREEN}✅ Fixed${NC}"
fi

echo ""
echo -e "${BLUE}📋 Setup Summary${NC}"
echo "=================="

if command -v cipher &> /dev/null; then
    if [[ -f "memAgent/.env" ]] && grep -q "OPENAI_API_KEY=" "memAgent/.env" && ! grep -q "OPENAI_API_KEY=$" "memAgent/.env"; then
        echo -e "${GREEN}🎉 Ready to use! Your TSMS Cipher integration is properly set up.${NC}"
        echo ""
        echo "Quick start:"
        echo "1. Load knowledge: ./scripts/tsms-cipher-memory.sh --load-bundle"
        echo "2. Start agent:    ./scripts/tsms-cipher-memory.sh --enable-memory --cipher-mode mcp"
    else
        echo -e "${YELLOW}⚙️  Almost ready! Please configure your OpenAI API key:${NC}"
        echo "   Edit memAgent/.env and add: OPENAI_API_KEY=your_actual_key_here"
    fi
else
    echo -e "${YELLOW}📦 Install required: npm install -g @byterover/cipher${NC}"
fi

echo ""
echo -e "${BLUE}📚 Documentation: docs/tsms-cipher-integration.md${NC}"
