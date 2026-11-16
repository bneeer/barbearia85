# Documentation maintenance

Whenever you add or change an application feature, update the relevant user instructions in `docs/help/` in the same change. Describe the actual user workflow, any important constraints or error states, and which roles can use it. If a new help document is needed, register it in `HelpController::DOCUMENTS` with the correct audience so the Help page exposes it only to authorized roles. Keep the rendered Help page and its role-specific content aligned with the navigation and server-side permissions; do not document unavailable controls as usable.
