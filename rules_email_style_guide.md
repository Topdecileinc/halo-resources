---
title: Email style guide
eyebrow: Email Design System
---

# Email style guide

Visual standards for every Halo email. The values here are the single source
of truth for the build — the validator flags any value outside this set,
and the AI orchestrator pulls from this file when assembling new emails.

{% include section.html
   first="true"
   eyebrow="COLOR"
   title="The Halo email palette"
   lead="<strong>Yellow</strong> drives action, <strong>blue</strong> marks links and selection, and <strong>deep navy</strong> anchors text. Supporting surface tints extend the same cool, brand-anchored palette into backgrounds." %}

{% include subsection.html title="Brand colors" lead="The three roles that do the heavy lifting. Use saturated values on CTAs, primary links, and dark ink surfaces — never as decorative fill." %}

{% include color-grid-start.html %}

{% include color-card.html
   variant="saturated"
   name="Action primary"
   token="halo-color-yellow"
   hex="#FCD62D"
   usage="Primary CTAs, promo actions, top-of-funnel offers." %}

{% include color-card.html
   variant="saturated"
   name="Link / selected"
   token="halo-color-blue"
   hex="#2F93F3"
   usage="Secondary CTAs, links, technical accents." %}

{% include color-card.html
   variant="saturated"
   name="Ink / dark"
   token="halo-color-ink"
   hex="#1B1B1B"
   usage="Headings, primary text, dark buttons, footer." %}

{% include color-grid-end.html %}

{% include subsection.html title="Surfaces" lead="Backgrounds and section fills. Keep these light — surfaces are scaffolding, not the message." %}

{% include color-grid-start.html %}

{% include color-card.html
   name="Page wash"
   token="halo-color-surface-page"
   hex="#FFFFFF"
   usage="Default email background." %}

{% include color-card.html
   name="Surface light"
   token="halo-color-surface-light"
   hex="#F5F5F5"
   usage="Alternate section backgrounds, content cards." %}

{% include color-card.html
   name="Surface alt"
   token="halo-color-surface-alt"
   hex="#F2F4F4"
   usage="Secondary section backgrounds." %}

{% include color-grid-end.html %}

{% include subsection.html title="Text" lead="Three text colors handle every email. Body uses the mid-gray; muted handles fine print and disclaimers; ink is reserved for headings and primary copy." %}

{% include color-grid-start.html %}

{% include color-card.html
   name="Body text"
   token="halo-color-text-body"
   hex="#333333"
   usage="Default body copy." %}

{% include color-card.html
   name="Dark surface text"
   token="halo-color-text-dark"
   hex="#434343"
   usage="Text on light surfaces with extra contrast needs." %}

{% include color-card.html
   name="Muted / fine print"
   token="halo-color-text-muted"
   hex="#9D9D9D"
   usage="Disclaimers, footer legal, secondary annotations." %}

{% include color-grid-end.html %}

{% include section.html
   eyebrow="TYPOGRAPHY"
   title="Type scale"
   lead="Inter on every Halo email, with web-safe fallbacks for email-client compatibility. Headings and body both run at <strong>400 (Regular)</strong> by default — the system stays unbold and lets size, color, and white space carry hierarchy. Subject lines, eyebrows, and labels switch to <strong>700 (Bold)</strong> for emphasis." %}

{% include type-stack-grid-start.html %}

{% include type-stack.html
   eyebrow="PRIMARY EMAIL STACK"
   token="halo-font-email"
   preview="Halo email body text"
   stack='"Inter", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif' %}

{% include type-stack.html
   eyebrow="LEGAL & FINE-PRINT STACK"
   token="halo-font-legal"
   preview="LEGAL · DISCLAIMERS · FINE PRINT"
   stack='Arial, Helvetica, sans-serif' %}

{% include type-stack-grid-end.html %}

{% include subsection.html title="Type roles" lead="Every email uses these roles. Sizes are calibrated for inbox rendering — they read at the same hierarchy in Gmail, Outlook, and Apple Mail." %}

{% include type-role.html
   role="HERO / H1"
   token="halo-type-hero"
   size="32px"
   weight="700"
   leading="1.1"
   tracking="-0.01em"
   sample="Save $75 on the Halo Collar 5"
   usage="Top of the email — the campaign headline." %}

{% include type-role.html
   role="SECTION / H2"
   token="halo-type-section"
   size="22px"
   weight="650"
   leading="1.2"
   tracking="0"
   sample="Built for adventures together"
   usage="Section breaks within longer emails." %}

{% include type-role.html
   role="BODY"
   token="halo-type-body"
   size="16px"
   weight="400"
   leading="1.55"
   tracking="0"
   sample="The quick brown fox jumps over the lazy dog. Designed for every dog, every property, every adventure."
   usage="Default body copy throughout the email." %}

{% include type-role.html
   role="FINE PRINT"
   token="halo-type-fine"
   size="11px"
   weight="400"
   leading="1.4"
   tracking="0.02em"
   sample="Protect Animals with Satellites LLC d/b/a Halo Collar | 55 S.E. 2nd Avenue #15R | Delray Beach, FL 33444"
   usage="Footer legal line and disclaimers." %}

{% include section.html
   eyebrow="LAYOUT"
   title="Container, spacing, rhythm"
   lead="Email is a fixed-width medium. The container is 600px on desktop, fluid below 600. Within the container, a four-step spacing scale handles every padding, gap, and gutter — never inject custom values." %}

{% include subsection.html title="Container width" lead="Every Halo email uses the same 600px outer container. Mobile clients render at their own scale; the build doesn't fight that." %}

<div class="halo-bars">
{% include width-bar.html name="Email body" token="halo-container-email" width_px="600" max_px="600" %}
</div>

{% include subsection.html title="Spacing scale" lead="Four base units — used for section padding, gaps between blocks, and content insets. Doubling above 32 for larger sections." %}

{% include spacing-scale.html values="8,16,24,32,48" %}

{% include section.html
   eyebrow="SURFACE TOKENS"
   title="Radius & shape"
   lead="Two radius values cover every email use case. The validator flags any other value." %}

{% include surface-grid-start.html %}

{% include surface-card.html
   name="Container radius"
   token="halo-radius-container"
   value="24px" %}

{% include surface-card.html
   name="Pill radius (CTA only)"
   token="halo-radius-pill"
   value="999px" %}

{% include surface-grid-end.html %}

{% include section.html
   eyebrow="COMPONENTS"
   title="Component review area"
   lead="The live element catalog for this design system. The actual build artifacts live in <code>email-design-system/components/</code> as <code>component_*.html</code> files; these previews mirror what the build produces." %}

{% capture btn_demos %}
<button class="halo-demo-btn halo-demo-btn--primary">Shop Now</button>
<button class="halo-demo-btn halo-demo-btn--secondary">Learn more</button>
<button class="halo-demo-btn halo-demo-btn--ghost">View details</button>
<button class="halo-demo-btn halo-demo-btn--disabled" disabled>Unavailable</button>
{% endcapture %}

{% capture badge_demos %}
<span class="halo-demo-badge halo-demo-badge--neutral">NEW</span>
<span class="halo-demo-badge halo-demo-badge--sale">SAVE $75</span>
<span class="halo-demo-badge halo-demo-badge--membership">MEMBER PERK</span>
<span class="halo-demo-badge halo-demo-badge--featured">MOST POPULAR</span>
<span class="halo-demo-badge halo-demo-badge--outline">WHILE SUPPLIES LAST</span>
{% endcapture %}

{% include comp-rows-start.html %}

{% include comp-row.html
   name="Buttons"
   desc="Primary, secondary, ghost, and disabled states. Pills only on CTAs."
   demos=btn_demos %}

{% include comp-row.html
   name="Badges"
   desc="Status, offer, and inventory labels. Bold text, not interactive."
   demos=badge_demos %}

{% include comp-rows-end.html %}

{% include section.html
   eyebrow="GUARDRAILS"
   title="What not to do"
   lead="Hard rules the validator enforces. If you find yourself reaching for something on this list, the right answer is almost always a different primitive from the system above." %}

{% include forbidden-start.html title="Never" %}

- Use italic tags (`<i>`, `<em>`) for emphasis — use bold or color instead.
- Use border-radius values outside `24px` and `999px`.
- Apply pill styling to non-clickable elements (badges, eyebrows, offer callouts).
- Use hex colors outside the documented palette.
- Write `font-family` without Inter and a web-safe fallback.
- Use `display: flex` or `display: grid` — email clients don't support them.
- Use em dashes (`—`) anywhere in copy — use commas or colons.

{% include forbidden-end.html %}
