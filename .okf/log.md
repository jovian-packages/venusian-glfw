# Log

## 2026-10-28

* **Version**: 0.11.0 with the Venusian 0.11 ecosystem: `dev-main` → `0.11.x-dev`, its extensions, `venusian-surface/*`, `venusian-voyager/*` and the jovian siblings at `^0.11.0`, PHP `~8.4.27||^8.5.12`, homepage `0.11.x`. No API change. 17 passed, 2 skipped, 2 failed on Homebrew PHP 8.4 NTS and ZTS; 19 passed, 2 skipped on the Pi (8.4 ZTS).
* **Open — Metal layer on macOS**: `GlfwStagedWindow` (`src/Windows/GlfwStagedWindow.php:681`) builds its Metal view with `NSView::initWithFrame(new NSRect())`, and ext-appkit's `NSRect::__construct()` takes exactly four arguments, so lending a Metal layer throws `ArgumentCountError` (`StagedWindowTest` "lends a Metal layer"). Independent of the version change.

## 2026-10-08

* Staged windows phase 6: show-pass presents, vsync, frames in flight, HDR, hidden windows, see [architecture](architecture/stager.md).

## 2026-10-08

* venusian-glfw 0.10.0: the `glfw` stager. [stager](architecture/stager.md), [testing](runbooks/testing.md). Suite 15 passed on the Mac (php84, zhp; Vulkan skipped, GLFW finds no loader there), 14 on Pi Wayland, 15 on Pi X11.
