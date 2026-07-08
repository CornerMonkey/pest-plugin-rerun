# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Current state

This repository is empty (freshly `git init`'d, no commits, no files). It is presumably intended to become a Pest
(PHP testing framework) plugin, based on the repo name `pest-plugin-rerun`, but no scaffolding exists yet.

Once code is added, replace this file with real, verified content: actual build/test/lint commands from the
project's `composer.json`, and real architecture notes based on the code that exists. Do not carry forward any
assumptions below without checking them against the actual project files first.

## Conventions for Pest plugins (general knowledge, unverified against this repo)

Pest plugins are typically structured as Composer packages that:
- Require `pestphp/pest` and implement `Pest\Plugin` interfaces (e.g. `HandlesArguments`, `AddsOutput`,
  `Bootable`) depending on what the plugin needs to hook into.
- Are registered via a `PestPlugins` entry or autoloaded through Composer's `extra.pest` config in `composer.json`.
- Keep plugin source under `src/` and tests under `tests/`, following PSR-4 autoloading.
- Are tested by running Pest against the plugin's own test suite (`vendor/bin/pest`), often with a minimal
  fixture app or in-memory test scenarios exercising the plugin's hook points.

Confirm these against the actual `composer.json` and source layout once they exist — do not assume this structure
is correct without checking.
