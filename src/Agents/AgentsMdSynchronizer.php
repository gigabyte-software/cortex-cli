<?php

declare(strict_types=1);

namespace Cortex\Agents;

final class AgentsMdSynchronizer
{
    public const MARKER_BEGIN = '<!-- CORTEX_AGENTS_MANAGED_BEGIN -->';

    public const MARKER_END = '<!-- CORTEX_AGENTS_MANAGED_END -->';

    public function __construct(
        private readonly AgentsManagedBodyProvider $bodyProvider = new AgentsManagedBodyProvider(),
    ) {
    }

    /**
     * @param string|null $innerMarkdownOverride For tests only; when set, skips loading bundled templates
     *
     * @return bool True if AGENTS.md was created or modified
     */
    public function sync(string $projectRoot, ?string $innerMarkdownOverride = null): bool
    {
        if ($this->shouldSkipForEnv()) {
            return false;
        }

        $projectRoot = rtrim($projectRoot, '/');
        $path = $projectRoot . '/AGENTS.md';

        $innerMarkdown = $innerMarkdownOverride ?? $this->bodyProvider->getMarkdown();
        $managedSection = $this->buildManagedSection($innerMarkdown);

        $existing = is_file($path) ? file_get_contents($path) : false;
        $existing = $existing === false ? null : $existing;

        if ($existing !== null && str_contains($existing, self::MARKER_BEGIN) && str_contains($existing, self::MARKER_END)) {
            $beginPos = strpos($existing, self::MARKER_BEGIN);
            $endPos = strpos($existing, self::MARKER_END);
            if ($beginPos === false || $endPos === false || $endPos < $beginPos) {
                return $this->writeAppended($path, $existing, $managedSection);
            }

            $endClose = $endPos + strlen(self::MARKER_END);
            $prefix = substr($existing, 0, $beginPos);
            $suffix = substr($existing, $endClose);
            $oldManaged = substr($existing, $beginPos, $endClose - $beginPos);

            if (hash('sha256', $oldManaged) === hash('sha256', $managedSection)) {
                return false;
            }

            return $this->writeAtomic($path, $prefix . $managedSection . $suffix);
        }

        if ($existing !== null && str_contains($existing, self::MARKER_BEGIN)) {
            return $this->writeAppended($path, $existing, $managedSection);
        }

        if ($existing !== null) {
            $trimmed = rtrim($existing);

            return $this->writeAtomic($path, $trimmed === '' ? $managedSection : $trimmed . "\n\n" . $managedSection);
        }

        $intro = "# Agent instructions\n\n"
            . "Add project-specific notes for AI assistants above the Cortex-managed section.\n\n";

        return $this->writeAtomic($path, $intro . $managedSection);
    }

    private function shouldSkipForEnv(): bool
    {
        $v = getenv('CORTEX_SKIP_AGENTS_SYNC');

        return $v !== false && $v !== '' && !in_array(strtolower(trim($v)), ['0', 'false', 'no'], true);
    }

    private function buildManagedSection(string $innerMarkdown): string
    {
        $header = <<<'MD'
---
### Cortex-managed agent rules

Cortex CLI replaces everything between the HTML comment markers below. Add project-specific instructions **above** `CORTEX_AGENTS_MANAGED_BEGIN`. Do not edit between the markers.

---
MD;

        return self::MARKER_BEGIN . "\n"
            . rtrim($header) . "\n\n"
            . rtrim($innerMarkdown) . "\n"
            . self::MARKER_END;
    }

    private function writeAppended(string $path, string $existing, string $managedSection): bool
    {
        $trimmed = rtrim($existing);

        return $this->writeAtomic($path, $trimmed . "\n\n" . $managedSection);
    }

    private function writeAtomic(string $path, string $content): bool
    {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            return false;
        }

        $tmp = $directory . '/.agents.' . bin2hex(random_bytes(8)) . '.tmp';

        if (file_put_contents($tmp, $content) === false) {
            return false;
        }

        if (PHP_OS_FAMILY === 'Windows' && is_file($path)) {
            @unlink($path);
        }

        if (!@rename($tmp, $path)) {
            $ok = @copy($tmp, $path);
            @unlink($tmp);

            return $ok;
        }

        return true;
    }
}
