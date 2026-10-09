# Security Policy

## Supported versions

Venusian is pre-1.0. No 0.x release receives security fixes or advisories; fixes land in the
next release line. Security support starts with 1.0.

| Version | Security fixes |
|---------|----------------|
| < 1.0   | No             |

## Reporting a vulnerability

Please don't open a public issue for a security problem.

Report it privately through GitHub: the **Report a vulnerability** button on this repository's
**Security** tab. If that isn't available, email **info@projectsaturnstudios.com**.

Include what you found, the affected version, and steps to reproduce. Reports are read and
weighed for the release line in development; before 1.0 there is no response-time commitment.

## Security model

- A frame uploaded by address is trusted to hold `stride × height` bytes: the address comes from an ext-fb framebuffer's `pointer()`, never from user input. A frame held as a string is checked by ext-opengl against the unpack state before GL reads it.
- A borrower's `instance` handle is trusted to be a `VkInstance` (ext-vulkan's `fromPointer` rule); it comes only from an engine's `lendingHandles()`.
- An icon is checked by Surface for exactly `width × height × 4` bytes before GLFW sees it.
