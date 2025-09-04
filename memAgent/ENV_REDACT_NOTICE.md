REDACTED LOCAL ENV NOTICE

This repository's `memAgent/.env` file contains sensitive API keys (OpenAI, Azure, Anthropic).

Actions taken locally:
- The working copy of `memAgent/.env` has been replaced with a placeholder value for `OPENAI_API_KEY`.
- `memAgent/.env` is listed in `.gitignore` to prevent accidental commits.

Recommended next steps (manual, optional):
1. If the real OpenAI key was committed in earlier commits, rotate the key immediately via the OpenAI dashboard.
2. If you need to scrub history, use `git filter-repo` or BFG to remove the key from past commits (this is destructive and requires coordination with collaborators).
3. Keep an up-to-date `memAgent/.env.example` with placeholder values for onboarding.

Do NOT put the real key in the repository. Export it in your shell before starting the memAgent, or use a secrets manager.
