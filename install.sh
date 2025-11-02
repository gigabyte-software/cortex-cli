#!/bin/bash

set -e

echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo " 🚀 Installing Cortex CLI"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""

# Check if cortex.phar exists
if [ ! -f "cortex.phar" ]; then
    echo "❌ Error: cortex.phar not found in current directory"
    echo ""
    echo "Download it first:"
    echo "  curl -L https://github.com/YOUR-ORG/cortex-cli/releases/latest/download/cortex.phar -o cortex.phar"
    echo ""
    exit 1
fi

# Install PHAR to /usr/local/bin
echo "📦 Installing Cortex CLI..."
if sudo cp cortex.phar /usr/local/bin/cortex; then
    sudo chmod +x /usr/local/bin/cortex
    echo "   ✓ Installed to /usr/local/bin/cortex"
else
    echo "   ❌ Failed to install. Try running with sudo"
    exit 1
fi

echo ""

# Detect shell and install completion
SHELL_NAME=$(basename "$SHELL")

case "$SHELL_NAME" in
    bash)
        echo "🔧 Installing Bash completion..."
        if cortex completion bash | sudo tee /etc/bash_completion.d/cortex > /dev/null 2>&1; then
            echo "   ✓ Bash completion installed"
            RELOAD_CMD="source ~/.bashrc"
        else
            echo "   ⚠  Could not install system-wide completion"
            echo "   💡 Installing to user directory instead..."
            
            COMPLETION_FILE="$HOME/.bash_completion"
            cortex completion bash >> "$COMPLETION_FILE"
            echo "   ✓ Bash completion installed to $COMPLETION_FILE"
            RELOAD_CMD="source ~/.bashrc"
        fi
        ;;
        
    zsh)
        echo "🔧 Installing Zsh completion..."
        if cortex completion zsh | sudo tee /usr/share/zsh/vendor-completions/_cortex > /dev/null 2>&1; then
            echo "   ✓ Zsh completion installed"
            RELOAD_CMD="source ~/.zshrc"
        else
            echo "   ⚠  Could not install system-wide completion"
            echo "   💡 Installing to user directory instead..."
            
            COMP_DIR="$HOME/.zsh/completion"
            mkdir -p "$COMP_DIR"
            cortex completion zsh > "$COMP_DIR/_cortex"
            echo "   ✓ Zsh completion installed to $COMP_DIR/_cortex"
            
            # Check if fpath is configured
            if ! grep -q "fpath=.*\.zsh/completion" ~/.zshrc 2>/dev/null; then
                echo ""
                echo "   📝 Add this to your ~/.zshrc:"
                echo "      fpath=($COMP_DIR \$fpath)"
                echo "      autoload -U compinit && compinit"
            fi
            
            RELOAD_CMD="source ~/.zshrc"
        fi
        ;;
        
    *)
        echo "⚠  Unknown shell: $SHELL_NAME"
        echo "   Supported shells: bash, zsh"
        echo "   Tab completion not installed"
        RELOAD_CMD=""
        ;;
esac

echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo " ✅ Installation Complete!"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""
echo "🎯 Quick Start:"
echo ""
echo "  1. Activate completion:"
if [ -n "$RELOAD_CMD" ]; then
    echo "     $RELOAD_CMD"
fi
echo ""
echo "  2. Verify installation:"
echo "     cortex --version"
echo ""
echo "  3. Get started:"
echo "     cd your-project/"
echo "     cortex up"
echo ""
echo "📚 Documentation: https://github.com/YOUR-ORG/cortex-cli"
echo ""

