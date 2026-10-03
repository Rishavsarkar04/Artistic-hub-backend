<x-mail::message>
# Thank you for your order

Hi {{ $order->customer_name }}, we've received your payment. Your order **{{ $order->order_number }}** is confirmed.

<x-mail::table>
| Item | Qty | Amount |
|:-----|:---:|-------:|
@foreach ($order->items as $item)
| {{ $item->product_name }} ({{ $item->variant_name }}) | {{ $item->quantity }} | {{ $symbol }}{{ $item->total_amount }} |
@endforeach
| **Total** | | **{{ $symbol }}{{ $order->total_amount }}** |
</x-mail::table>

Prices include GST.

**Delivering to**<br>
{{ $order->recipient_name }}, {{ $order->recipient_phone }}<br>
{{ $order->address_line_1 }}@if ($order->address_line_2), {{ $order->address_line_2 }}@endif<br>
{{ $order->city }}, {{ $order->state }} {{ $order->postal_code }}<br>
{{ $order->country }}

<x-mail::button :url="$ordersUrl">
View your order
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
