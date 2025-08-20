#!/bin/bash

# BMad-Method + TSMS Cipher Integration Script
# Loads the team-fullstack bundle into Cipher memory for enhanced development

set -e

# Colors
GREEN='\033[0;32m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m'

echo -e "${CYAN}🚀 BMad-Method Team-Fullstack + TSMS Cipher Integration${NC}"
echo "==========================================================="

# Load environment
if [[ -f "memAgent/.env" ]]; then
    source memAgent/.env
    echo -e "${GREEN}✅ Environment loaded${NC}"
fi

# Check if team bundle exists
BUNDLE_FILE="web-bundles/teams/team-fullstack.txt"
if [[ ! -f "$BUNDLE_FILE" ]]; then
    echo -e "${RED}❌ Team bundle not found: $BUNDLE_FILE${NC}"
    exit 1
fi

echo -e "${BLUE}📚 Loading BMad-Method Team-Fullstack Bundle into Cipher...${NC}"
echo "Bundle size: $(wc -l < "$BUNDLE_FILE") lines"

# Load the BMad-Method bundle content into Cipher
cipher --mode cli "I'm going to provide you with the complete BMad-Method team-fullstack bundle. This contains comprehensive development methodology, specialized agents (orchestrator, analyst, PM, UX expert, architect, PO), and workflows for full-stack development. Please analyze and memorize this content so you can help with TSMS development using BMad-Method principles.

$(cat "$BUNDLE_FILE")"

echo -e "${GREEN}✅ BMad-Method Team-Fullstack bundle loaded into Cipher memory!${NC}"
echo ""
echo -e "${CYAN}🎯 Available BMad-Method Agents:${NC}"
echo "• BMad Orchestrator 🎭 - Workflow coordination, role switching"
echo "• Analyst 📊 - Requirements analysis, system analysis"  
echo "• PM 📋 - Project management, planning"
echo "• UX Expert 🎨 - User experience, interface design"
echo "• Architect 🏗️ - System design, technical architecture"
echo "• PO 📱 - Product ownership, business requirements"
echo ""
echo -e "${CYAN}🛠️ Available Workflows:${NC}"
echo "• Brownfield Full-Stack, Service, UI"
echo "• Greenfield Full-Stack, Service, UI"
echo ""
echo -e "${GREEN}🎉 Ready! You can now ask Cipher about BMad-Method approaches for your TSMS project.${NC}"
