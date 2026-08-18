# Fase V S17 — Test carritos abandonados
Fecha: 2026-05-18

## Hooks verificados
- woocommerce_add_to_cart → capture_cart() ✅
- woocommerce_payment_complete → mark_recovered() ✅
- woocommerce_order_status_completed → on_order_complete() ✅
- ATORA_Abandoned_Cart_Service::init() en bootstrap ✅

## Preset cart_recovery corregido
- trigger_type: cart_abandoned (era form_submitted — BUG CORREGIDO)
- Acciones: send_email x3 (60min, 24h, 72h)

## Hub KPI actualizado
- /abandoned-carts/summary → d.summary.active
