# Design System: The Nocturne Editorial (Vasupon-Site)

## 1. Overview
**Creative North Star: "The Obsidian Gallery"**
This design system rejects the cluttered, "boxy" nature of standard web interfaces in favor of a high-end editorial experience. It is inspired by the quiet confidence of physical luxury goods—matte finishes, silver foil embossing, and expansive architectural spaces. 

Instead of a traditional grid-heavy layout, we employ **Intentional Asymmetry**. Imagine a gallery wall: some elements are centered and monolithic, while others are offset to create a sense of rhythm and discovery. We replace loud, "click-me" UI with a philosophy of "Blackspace"—treating the dark background not as an empty void, but as a premium canvas that gives every element the room to breathe and command authority.

## 2. Typography
We use high-contrast pairings to evoke the feeling of a luxury masthead.

- **Display & Headlines (`NOTO_SERIF`)**: These are our "hero" elements. Use `display-lg` for impactful, short statements. Set these with tight letter-spacing (-0.02em) to give them a customized, bespoke look.
- **Body & Labels (`MANROPE`)**: A clean, technical sans-serif that balances the classicism of the serif.
- **Contrast as Hierarchy**: Always use `on_surface` (#e5e2e1) for primary text. Use `on_surface_variant` (#c6c6cb) for secondary "meta" information. This 20% drop in contrast creates a sophisticated visual hierarchy without needing to change font sizes.

## 3. Color Palette
The palette is rooted in `#131313`, a charcoal base that feels softer and more expensive than pure black. 

| Role | Color Name | Hex |
| :--- | :--- | :--- |
| **Primary** | `primary` | `#a0c9ff` |
| | `on_primary` | `#00325a` |
| | `primary_container` | `#001125` |
| | `on_primary_container` | `#4f7fb7` |
| **Secondary** | `secondary` | `#a0d1b8` |
| | `on_secondary` | `#033826` |
| | `secondary_container` | `#22513e` |
| | `on_secondary_container` | `#92c3aa` |
| **Tertiary** | `tertiary` | `#c6c6c6` |
| | `on_tertiary` | `#2f3131` |
| | `tertiary_container` | `#0f1111` |
| | `on_tertiary_container` | `#7c7d7d` |
| **Surface** | `surface` | `#131313` |
| | `on_surface` | `#e5e2e1` |
| | `surface_variant` | `#353534` |
| | `on_surface_variant` | `#c6c6cb` |
| | `surface_container_lowest`| `#0e0e0e` |
| | `surface_container_low` | `#1c1b1b` |
| | `surface_container` | `#201f1f` |
| | `surface_container_high` | `#2a2a2a` |
| | `surface_container_highest`| `#353534` |
| | `surface_bright` | `#393939` |
| | `surface_dim` | `#131313` |
| **Background** | `background` | `#131313` |
| | `on_background` | `#e5e2e1` |
| **Error** | `error` | `#ffb4ab` |
| | `on_error` | `#690005` |
| | `error_container` | `#93000a` |
| | `on_error_container` | `#ffdad6` |
| **Outline** | `outline` | `#8f9095` |
| | `outline_variant` | `#45474b` |

*Note: Theme Mode is set to **DARK**, using `customColor` `#0D1117` as the tint origin.*

## 4. Design Guidelines 

### The "No-Line" Rule
To maintain a premium feel, **1px solid borders are prohibited for sectioning.** Boundaries must be defined through tonal shifts. For example:
- A `surface` section transitions into a `surface-container-low` section to indicate a change in content.
- Use `surface-container-lowest` (`#0e0e0e`) to create deep "wells" of content that feel recessed into the page.

### Surface Hierarchy & Nesting
Layering is our primary tool for information architecture. 
- **Base Layer:** `surface` (#131313).
- **Secondary Layer:** `surface-container` (#201f1f).
- **Interactive/Top Layer:** `surface-container-highest` (#353534).
Treat the UI as stacked sheets of fine, dark cardstock. A card shouldn't have a border; it should simply be one step higher in the `surface-container` tier than its parent.

### The "Glass & Gradient" Rule
Flat color is the enemy of luxury. 
- **Signature Gradients:** For primary CTAs and hero highlights, use subtle linear gradients from `primary` (#a0c9ff) to `on_primary_container` (#4f7fb7) at a 135-degree angle.
- **Midnight Accents:** Infuse the deep emerald and midnight blue tones using `secondary_container` (#22513e) and `primary_container` (#001125) as background washes for large, immersive sections.

### Elevation & Depth: Tonal Layering
Traditional shadows look "webby." In this system, we use light and transparency to define space.
- **The Layering Principle:** Place a `surface-container-lowest` card inside a `surface-container-low` section. This "negative lift" creates a sophisticated, recessed look.
- **Ambient Shadows:** For floating elements (Modals/Popovers), use a shadow with a 40px–60px blur at 6% opacity, tinted with the `primary` color. It should feel like a soft glow, not a dark stain.
- **The "Ghost Border" Fallback:** If a container absolutely requires a boundary (e.g., a form input), use `outline_variant` (#45474b) at **15% opacity**. It should be felt, not seen.
- **Glassmorphism:** For navigation bars or floating action buttons, use `surface_container` at 70% opacity with a `backdrop-blur` of 20px. This allows the "Midnight Blue" accents to bleed through the UI, creating a sense of unity.

### Components

**Buttons**
- **Primary:** No rounded corners (`0px`). Use the `primary` to `on_primary_container` gradient. Text is `on_primary_fixed` (#001c37), uppercase, `label-md`.
- **Secondary:** Transparent background with a "Ghost Border" (15% opacity `outline`). 
- **Interaction:** On hover, a button should not grow; instead, the background should shift from the gradient to a solid `primary_fixed` (#d2e4ff).

**Cards & Lists**
- **No Dividers:** Vertical spacing is your divider. Use the `16` (5.5rem) or `20` (7rem) spacing tokens to separate major content blocks. 
- **List Items:** Define hover states by shifting the background to `surface_bright` (#393939). Never use a horizontal line to separate list items; use a `2.5` (0.85rem) gap.

**Input Fields**
- **Style:** Underline only. Use the `outline` token at 30% opacity. When focused, the underline should animate to 100% opacity `primary`.
- **Labels:** Use `label-sm` in `on_surface_variant`. Always floating, never placeholder-only.

**Signature Component: The "Feature Monolith"**
A large, full-bleed section using `surface_container_lowest` with a centered `display-md` serif heading. Use 0.5 opacity `secondary` text for a small "01" or "02" index number above the heading to reinforce the editorial feel.

## 5. Do’s and Don’ts
### Do:
- **Embrace "Blackspace":** Use the `24` (8.5rem) spacing token between sections. Luxury is the ability to waste space.
- **Use Sharp Corners:** The `0px` border-radius is non-negotiable. It communicates architectural precision.
- **Mix Weights:** Pair a `display-lg` (light weight) heading with a `label-md` (bold weight) sub-caption.

### Don't:
- **Don't use pure White:** Avoid `#FFFFFF`. Always use `on_surface` (#e5e2e1) or `primary_fixed` (#d2e4ff) to maintain the "Silver" and "Matte" aesthetic.
- **Don't use standard shadows:** If it looks like a default Material Design shadow, it's too heavy.
- **Don't use icons for everything:** Use text labels in `label-sm` instead of cryptic icons. Text is more elegant and authoritative.
- **Don't center everything:** Use the grid to offset body text to the right while keeping headlines to the left to create a "Golden Ratio" editorial flow.
