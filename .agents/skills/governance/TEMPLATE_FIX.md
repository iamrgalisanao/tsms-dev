# Technical Fix Record: [Title]

> [!NOTE]
> **Fix Identifier**: FIX-[YYYYMMDD]-[00X]
> **Related Issue**: [GitHub Issue # / Context Link]
> **Severity**: [Low / Medium / High / Critical]

## 1. Problem Description
Provide a concise summary of the bug. What was the observed behavior versus expected?

## 2. Root Cause Analysis (RCA)
- **Primary Cause**: Why did this happen? (e.g., Infinite recursion, race condition, missing query scope)
- **Trigger**: What specific condition caused the failure?

## 3. Technical Solution
Describe how the bug was fixed. Include key code changes and the logic behind the solution.
- **Guard Mechanism**: (e.g., Added recursion flag, added `try...finally`)
- **Impact Area**: Which components/models were affected and verified?

## 4. Regression Prevention
- **Automated Tests**: What tests were added to ensure this doesn't reoccur?
- **Architectural Guardrails**: Did this lead to a new rule in `gemini.md` or `coding-standards.md`?

## 5. Visual Proof
Embed screenshots or terminal logs demonstrating the verified fix.
