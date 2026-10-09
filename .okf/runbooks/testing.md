---
type: Runbook
title: Testing
description: Real windows on the Mac (php84, zhp) and the Pi (Wayland, X11); temporary path repositories for Surface's splits and venusian-vulkan.
resource: tests/
tags: [glfw, pest]
status: draft
generated: { by: claude-opus/5.5, at: 2026-10-08T18:00:00Z }
sources:
  - id: pest
    resource: tests/Pest.php
    title: tests/Pest.php
---

# Steps

* `tests/Pest.php` fails with a message without ext-glfw and ext-opengl. `stager()` is one driver for the process; `GLFW_TEST_PLATFORM` (`cocoa`, `wayland`, `x11`) picks GLFW's platform.
* Surface's 0.10 splits and jovian/venusian-vulkan are not on Packagist: add path repositories to `<surface>/src/Surface/*` and `../venusian-vulkan` for the run, `composer update`.
* Mac: `php84 -d memory_limit=128M vendor/bin/pest`, then `zhp`. The Vulkan lend skips where GLFW finds no Vulkan loader.
* Pi: copy this package, the Surface checkout and venusian-vulkan without `vendor/`, `.git` and locks (`tar` piped into `fnk`) to the same relative paths; then `WAYLAND_DISPLAY=wayland-0 XDG_RUNTIME_DIR=/run/user/1000 GLFW_TEST_PLATFORM=wayland php -d memory_limit=128M vendor/bin/pest`, and with `DISPLAY=:0 GLFW_TEST_PLATFORM=x11`.
* The suite shows windows, goes full screen and switches display modes: announce it before a run.
* After: remove `vendor/`, `composer.lock` and the repository entries; `composer.json` carries none.
