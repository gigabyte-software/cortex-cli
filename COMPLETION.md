# Tab Completion Setup

Due to PHAR packaging limitations, tab completion needs to be set up manually. It only takes a minute!

## Bash Completion

```bash
# Generate completion script from source (not PHAR)
cd /home/rob/projects/cortex-cli
./bin/cortex completion bash | sudo tee /etc/bash_completion.d/cortex

# Reload your shell
source ~/.bashrc
```

## Zsh Completion

```bash
# Generate completion script from source (not PHAR)
cd /home/rob/projects/cortex-cli
./bin/cortex completion zsh | sudo tee /usr/share/zsh/vendor-completions/_cortex

# Reload your shell
source ~/.zshrc
```

## Alternative: User Directory (No Sudo)

### Bash
```bash
./bin/cortex completion bash >> ~/.bash_completion
source ~/.bashrc
```

### Zsh
```bash
mkdir -p ~/.zsh/completion
./bin/cortex completion zsh > ~/.zsh/completion/_cortex

# Add to ~/.zshrc if not already there:
echo 'fpath=(~/.zsh/completion $fpath)' >> ~/.zshrc
echo 'autoload -U compinit && compinit' >> ~/.zshrc

source ~/.zshrc
```

## Test It

```bash
# Type this and hit TAB
cortex <TAB>

# Should show: up, down, status, and your custom commands
```

## Why Manual Setup?

Symfony Console's `completion` command tries to read files from its vendor directory at runtime, which doesn't work inside a PHAR. The workaround is to generate the completion script from the source version (not the PHAR) and install it manually.

**Note:** You only need to do this once per machine!

