# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.6.2](https://github.com/gigabyte-software/cortex-cli/compare/v1.6.1...v1.6.2) (2025-11-18)


### Bug Fixes

* change default timeout on user commands ([8ef7db7](https://github.com/gigabyte-software/cortex-cli/commit/8ef7db7f104b0cbbfdfaf63f152ed481810ace2d))

## [1.6.1](https://github.com/gigabyte-software/cortex-cli/compare/v1.6.0...v1.6.1) (2025-11-08)


### Bug Fixes

* fix phpstan errors ([6ac7724](https://github.com/gigabyte-software/cortex-cli/commit/6ac77243e1acfb66d0d2f8d15a7af11fccff95e6))
* use docker to scan ports becausse we were chec king ports inside the container than the cortex command was running, but we're using docker socket to create the containers on the host ([2467a44](https://github.com/gigabyte-software/cortex-cli/commit/2467a44a429b9487c8cf272955665ae1431eee28))

# [1.6.0](https://github.com/gigabyte-software/cortex-cli/compare/v1.5.6...v1.6.0) (2025-11-08)


### Features

* deploy Cortex Coder on a new release ([481bc95](https://github.com/gigabyte-software/cortex-cli/commit/481bc950d13694bbb0907aa93d7b2269c7c24e90))

## [1.5.6](https://github.com/gigabyte-software/cortex-cli/compare/v1.5.5...v1.5.6) (2025-11-08)


### Bug Fixes

* check if we can bind to a port not just if something is listening when checking for port conflicts ([4ba9a27](https://github.com/gigabyte-software/cortex-cli/commit/4ba9a274b82117b55c3b1400050745ef1c84af2a))

## [1.5.5](https://github.com/gigabyte-software/cortex-cli/compare/v1.5.4...v1.5.5) (2025-11-08)


### Bug Fixes

* container cleanup ([40e4984](https://github.com/gigabyte-software/cortex-cli/commit/40e49844730a25953ccbb39da9b9d2eab33fad0c))

## [1.5.4](https://github.com/gigabyte-software/cortex-cli/compare/v1.5.3...v1.5.4) (2025-11-08)


### Bug Fixes

* cleanup stale containers ([f060f86](https://github.com/gigabyte-software/cortex-cli/commit/f060f86b04ddaa79e5a4c0bf16aa406ff0bfc6c8))

## [1.5.3](https://github.com/gigabyte-software/cortex-cli/compare/v1.5.2...v1.5.3) (2025-11-08)


### Bug Fixes

* port offset ([c84f736](https://github.com/gigabyte-software/cortex-cli/commit/c84f73636fda4395ec77eaf59857bcf1cf6477d6))

## [1.5.2](https://github.com/gigabyte-software/cortex-cli/compare/v1.5.1...v1.5.2) (2025-11-08)


### Bug Fixes

* container namespacing ([5b045e0](https://github.com/gigabyte-software/cortex-cli/commit/5b045e037cb81f1477d9d69a80b763442da00491))

## [1.5.1](https://github.com/gigabyte-software/cortex-cli/compare/v1.5.0...v1.5.1) (2025-11-08)


### Bug Fixes

* container namespacing wasn't working correctly ([e6b00b1](https://github.com/gigabyte-software/cortex-cli/commit/e6b00b1cd8792e9d90d7135c753bf7b06e0572a7))

# [1.5.0](https://github.com/gigabyte-software/cortex-cli/compare/v1.4.0...v1.5.0) (2025-11-08)


### Bug Fixes

* fix phpstan tests ([591a14f](https://github.com/gigabyte-software/cortex-cli/commit/591a14f50f09ff3deb0c41ecc45e50868b9eaa53))


### Features

* added --avoid-conflicts and other options for namspacing containers and avoiding port conflicts ([ee0a894](https://github.com/gigabyte-software/cortex-cli/commit/ee0a894d2ecdda95deb2faff2319d60c64c6a254))

# [1.4.0](https://github.com/gigabyte-software/cortex-cli/compare/v1.3.0...v1.4.0) (2025-11-08)


### Features

* Added app_url option to specify how to access the app after cortex up ([af01d60](https://github.com/gigabyte-software/cortex-cli/commit/af01d6052241acf1fe1f98c81adefb03e2add224))
* Added app_url option to specify how to access the app after cotext up ([cd51c30](https://github.com/gigabyte-software/cortex-cli/commit/cd51c3089619d6f8687fb976623284cdd6d8427e))

# [1.3.0](https://github.com/gigabyte-software/cortex-cli/compare/v1.2.2...v1.3.0) (2025-11-06)


### Bug Fixes

* fix unit tests for shellf command ([1303be1](https://github.com/gigabyte-software/cortex-cli/commit/1303be10f07498283862adec20cc1869416270b5))


### Features

* Add cortex shell command ([02eadb9](https://github.com/gigabyte-software/cortex-cli/commit/02eadb93e5e97b896879ac15dcc2bcb44e6c935d))
* Implement cortex shell subcommand ([3527cdd](https://github.com/gigabyte-software/cortex-cli/commit/3527cdd9414cc42ae789f91abb626758046e8647))

## [1.2.2](https://github.com/gigabyte-software/cortex-cli/compare/v1.2.1...v1.2.2) (2025-11-06)


### Bug Fixes

* make sure .gitkeep is added to .cortex/ folders ([157010f](https://github.com/gigabyte-software/cortex-cli/commit/157010f5f1a97ed0923371de89917c4c63facad6))

## [1.2.1](https://github.com/gigabyte-software/cortex-cli/compare/v1.2.0...v1.2.1) (2025-11-06)

# [1.2.0](https://github.com/gigabyte-software/cortex-cli/compare/v1.1.1...v1.2.0) (2025-11-06)


### Features

* Add init command for project setup ([a0ce800](https://github.com/gigabyte-software/cortex-cli/commit/a0ce8007dab73b48387ec8ff9848732fbb7dc3bd))

## [1.1.1](https://github.com/gigabyte-software/cortex-cli/compare/v1.1.0...v1.1.1) (2025-11-06)

# [1.1.0](https://github.com/gigabyte-software/cortex-cli/compare/v1.0.6...v1.1.0) (2025-11-06)


### Bug Fixes

* **ci:** add package-lock.json for reproducible builds ([b12557f](https://github.com/gigabyte-software/cortex-cli/commit/b12557f9a34d77fed0bfba5f847201dc9d0a3cdd))


### Features

* fully automated releases with semantic-release ([d9704f3](https://github.com/gigabyte-software/cortex-cli/commit/d9704f3da61ce1df103218cc35c9ade71521b058))
