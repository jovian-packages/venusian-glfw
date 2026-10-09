# AGENTS.md

1. Bindings are ext-glfw's and ext-opengl's, 1:1. This package holds the stager, never native calls of its own.
2. What a platform cannot do is measured, never assumed: a capability is listed only where the suite shows it working on that platform.
3. Tests run against real windows on the Mac (php84 and zhp) and the Pi (Wayland and X11); nothing skips that the machine can run.
4. No engine code here: lent surfaces are made for engines, never drawn into.
5. Publish prep is part of done: README examples run, `.okf` validated.
