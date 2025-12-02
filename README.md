# Woo Advanced Sales Campaigns

A lightweight, developer-friendly WooCommerce extension for running **advanced sales campaigns** from a single place.

This plugin was originally built for Dragon Society International to manage complex, date-driven promotions (Black Friday / Cyber Monday, martial-arts–specific dates, etc.) with clear admin control and elegant front‑end display.

## Features

- **Campaign post type** (`Sales Campaigns` under WooCommerce)
  - Create multiple named campaigns (e.g., *Black Friday 2025*, *Rick Moneymaker Birthday Sale*).
  - Each campaign is independent and can have its own dates, discounts, targeting and messaging.

### Date Strategy & Status

- **Two date strategies** (per campaign):
  - **Fixed-date range** – simple start + end date window.
  - **Holiday-based window** – choose from built‑in or custom holidays, plus:
    - Offset in days (run *before* or *after* the holiday).
    - Duration in days.
- **Mode-aware status**
  - The campaign’s status is calculated **only** from the selected strategy:
    - If Date Strategy = *Fixed-date range*, only the fixed dates are used.
    - If Date Strategy = *Holiday-based window*, only the holiday window is used.
  - No mixing / fallback between strategies.
- **Status override**
  - `Automatic (based on date strategy)`
  - `Force Running (ignore schedule)`
  - `Force Ended (ignore schedule)`

### Holidays

- Built-in holiday helper for a given year, including:
  - Seasonal markers (First Day of Spring/Summer/Autumn/Winter).
  - Chinese New Year (modern range is table-based for accuracy).
  - Common Western holidays (New Year’s, Valentine’s, Easter, Independence Day, Halloween, Thanksgiving, Black Friday, Cyber Monday, Christmas, etc.).
- **Custom holidays UI**
  - WooCommerce → **Sales Holidays**.
  - Add arbitrary named dates (Month/Day) via a small table UI.
  - These custom holidays appear in the campaign Holiday dropdown for all years.

### Discount & Shipping

- **Discount types**
  - Percentage (%) off.
  - Fixed amount off.
- **Respects existing sales**
  - Option to **apply or skip** products already on sale.
- **Global free-shipping flag**
  - Per-campaign toggle: “Enable free shipping while this campaign is active (for matching products).”
  - Exposed as meta / runtime state so you can hook it into your shipping rules as needed.

Internally, discounts are **calculated against the regular price** and applied as the *best* campaign price when multiple campaigns are active and targeting the same product.

### Targeting

Per campaign you can mix and match:

- **Include / Exclude products**
  - AJAX **Select2** product search (typeahead).
- **Include / Exclude categories**
  - AJAX **Select2** term search for `product_cat`.
- **Include / Exclude tags**
  - AJAX **Select2** term search for `product_tag`.

Targeting rules are AND‑combined in a sensible way:

- If *include* lists are non‑empty, the product must match at least one include entry.
- If the product matches any *exclude* entry, it is excluded from the campaign.

### Front-end: Pricing & Savings

- Hooks into WooCommerce price filters:
  - `woocommerce_product_get_price`
  - `woocommerce_product_get_sale_price`
  - `woocommerce_product_is_on_sale`
  - `woocommerce_get_price_html`
- For matching products in active campaigns:
  - Calculates a discounted price from the **regular price**.
  - Applies the **lowest** discount if multiple campaigns apply.
  - Renders **regular price struck out** with sale price plus:
    - A savings line:  
      `You save $X (Y%)` on the next line in a smaller, styled font.

### Front-end: Countdown Timer

On product pages for items targeted by a running campaign with countdown enabled:

- Renders a **circular countdown** display with:
  - Days, hours, minutes, seconds.
- Uses the calculated campaign end timestamp as the target.
- Includes a small note below the timer:
  - “This is how much longer this special price is available.”

The timer is rendered via `woocommerce_single_product_summary` and updated every second with JavaScript.

### Per-Campaign Store Notice

Each campaign can optionally control the WooCommerce **store notice bar**:

- Checkbox: “Show a custom WooCommerce store notice while this campaign is active.”
- Textarea for the **custom message**.
- At runtime:
  - If at least one campaign is active and has store notices enabled:
    - The plugin forces `woocommerce_demo_store` **on** via filter.
    - Replaces the `woocommerce_demo_store_notice` message with the campaign’s custom message.
  - If multiple campaigns want a store notice, the **first active** campaign wins (per request).

This does not permanently change Woo options; it’s all done via filters.

## Technical Notes

- CPT: `wcas_campaign`
- Main class: `WCAS_Plugin`
- Primary meta keys (all prefixed with `_wcas_`):
  - `date_mode` (`fixed` or `holiday`)
  - `start_date`, `end_date`
  - `holiday_key`, `holiday_offset`, `holiday_duration`
  - `recurrence` (`none`, `yearly`)
  - `status` (manual override)
  - `discount_type`, `discount_value`
  - `apply_to_sale_items`
  - `show_countdown`
  - `free_shipping`
  - `include_products`, `exclude_products`
  - `include_cats`, `exclude_cats`
  - `include_tags`, `exclude_tags`
  - `store_notice_enable`, `store_notice_message`

### Important Behaviour

- `get_campaign_window()` is **mode-aware**:
  - Uses only fixed dates when `date_mode = fixed`.
  - Uses only holiday+offset+duration when `date_mode = holiday`.
- `get_campaign_status()`:
  - Applies the status override first.
  - Otherwise, uses `get_campaign_window()` and the current GMT timestamp to classify:
    - `unscheduled`, `scheduled`, `running`, or `ended`.

## Installation

1. Download the ZIP from your build/export.
2. In WordPress admin, go to **Plugins → Add New → Upload Plugin**.
3. Upload `woo-advanced-sales-campaigns.zip` and activate it.
4. Go to **WooCommerce → Sales Campaigns** to create your first campaign.
5. Optionally visit **WooCommerce → Sales Holidays** to add your own special dates.

## Development

The plugin is designed to be:

- **Self-contained** in a single main PHP file for easy reading.
- Easy to extend via:
  - Additional date strategies.
  - More recurrence options.
  - Custom shipping integrations consuming the `free_shipping` flag.
- Safe to version-control in Git and host on GitHub alongside your other WooCommerce tools.

You can fork and modify it freely for your own sites; it was written with a bias towards clarity and debuggability over micro‑optimisation.

## License

MIT (or your preferred license – update this section for public distribution).

