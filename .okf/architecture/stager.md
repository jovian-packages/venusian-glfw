---
type: Module
title: Stager
description: GlfwBridgeDriver, GlfwSession polled at the loop's pace, GlfwStagedWindow and GlfwDisplays.
resource: src/Windows/GlfwStagedWindow.php
tags: [glfw, staged-windows, bridge, opengl]
status: draft
generated: { by: claude-opus/5.5, at: 2026-10-08T18:00:00Z }
sources:
  - id: window
    resource: src/Windows/GlfwStagedWindow.php
    title: GlfwStagedWindow
  - id: session
    resource: src/Bridge/GlfwSession.php
    title: GlfwSession
  - id: displays
    resource: src/Windows/GlfwDisplays.php
    title: GlfwDisplays
---

# Overview

`VenusianGlfwServiceProvider::stage()` registers `glfw` on the toolkit manager; `boot()` calls it when `toolkit-bridge` is bound. `GlfwBridgeDriver` stages windows only; `config('bridge.stage.glfw.platform')` (`cocoa`, `wayland`, `x11`) picks GLFW's platform, an unknown name refused.

# Session

`GlfwSession`: `glfwInit` once (no GLFW menu bar; libdecor off, whose GTK plugin collides with ext-gtk's GTK; the platform hint when given; on macOS with ext-appkit the shared application first). `sleepsNatively()` false: a `ToolkitPoller`. `pump()` polls, or waits at most the budget; callbacks run inside and count as dispatched. The monitor callback posts `DisplaysChanged`.[^session]

# Window

Hints: hidden, resizable, decorated, floating, transparent framebuffer, scaled framebuffer, scale to monitor, position (where placeable), client API. OpenGL 4.1 core forward-compatible on macOS; OpenGL ES 3.1 over EGL elsewhere; `GLFW_NO_API` for a Vulkan lend. `focusable: false` throws at open: no platform refuses focus.

* Present: `withContext()` (remade with GL after a Vulkan lend), texture and read framebuffer at the frame's size, regions uploaded with row length and skips, blit flipped into `presentRect()` (nearest or linear), clear for bars, swap. `uploaded` lists the regions.
* Callbacks: position → `moved()` and display change; size → `resized()` and aspect correction; close → `closeRequested()`, should-close cleared when it stays open; refresh → next present whole; focus; iconify and maximize → `syncMode()`; content scale → `scaleChanged()`.
* Modes: leave the monitor (windowed rect remembered on entering); restore, maximize, iconify; cover a monitor at its desktop mode; hold a monitor at a video mode (desktop held in `GlfwDisplays`, released when GLFW gives it back). `syncMode()`: monitor held → Exclusive or Fullscreen; iconified; maximized; windowed.
* Capabilities: cocoa position, always-on-top, opacity, attention, exclusive; x11 those and icon; wayland attention. Measured on the Mac and the Pi.
* Resize: Wayland reports no size callback for a size asked for, and X11 the size only once applied: the asked size is corrected for the aspect range at once.
* Safe area: macOS with ext-appkit from the content view; else the whole window.[^window]

# Lending

`GL_CONTEXT`: CGLContextObj from `glfwGetNSGLContext` (ext-appkit), or EGL context and display; `presentLent()` makes it current, binds framebuffer 0, sets the swap interval from the lent vsync, copies, swaps. `METAL_LAYER`: a `CAMetalLayer` on an `NSView` pinned over GLFW's content view. `VULKAN_SURFACE`: window remade with `GLFW_NO_API`, `glfwCreateWindowSurface` against the borrower's `instance`, destroyed with `vkDestroySurfaceKHR` after the borrower let go. `remake()` carries place, limits, aspect, opacity, icon, vsync, visibility and mode.

# Displays

`GlfwDisplays`: id = `GLFWmonitor` address; bounds from monitor position and current mode; usable from the work area; scale and pixel density from the content scale; modes deduplicated by size and refresh; desktop = held pre-exclusive mode, else current; no HDR.[^displays]

[^session]: GlfwSession
[^window]: GlfwStagedWindow
[^displays]: GlfwDisplays

# Presenting

EGL swaps name changed rects (`EGL_KHR_swap_buffers_with_damage`): compositor redraws only those. Wayland swaps at interval 0 (compositor paces, never tears; hidden window never blocks). Hidden window presents nothing, CPU or lent surface: a buffer committed to a hidden GLFW Wayland window makes showing it a protocol error. First present after show = whole frame. GL borrower asking `'native'` gets the native window, remade without a context.
