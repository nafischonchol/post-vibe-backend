<?php

namespace App\Actions\Order;

use App\Enums\OrderStatusEnum;
use App\Events\OrderStatusUpdatedEvent;
use App\Http\Requests\Admin\Order\UpdateOrderStatusRequest;
use App\Jobs\CreateCourierParcelJob;
use App\Models\Order;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class UpdateOrderStatusAction
{
    public function execute(Order $order, UpdateOrderStatusRequest $request): Order
    {

        $newStatusStr = $request->input('status');
        $newStatus = OrderStatusEnum::from($newStatusStr);
        $note = $request->input('note');
        $userId = Auth::id();

        $currentStatus = $order->status;
        $currentRank = $this->getStatusRank($currentStatus);
        $newRank = $this->getStatusRank($newStatus);

        if ($newStatus === OrderStatusEnum::CANCELLED) {
            $shippedRank = $this->getStatusRank(OrderStatusEnum::SHIPPED);
            if ($currentRank >= $shippedRank) {
                abort(422, 'Cannot cancel this order because it has already been shipped or completed.');
            }
        }

        if ($newRank <= $currentRank) {
            abort(422, "Cannot change status from '{$currentStatus->value}' to '{$newStatus->value}'. Status transitions must go forward.");
        }

        $deliveryCharge = $request->input('delivery_charge');
        $courierName = $request->input('courier_name');

        $updatedOrder = DB::transaction(function () use ($order, $newStatus, $note, $userId, $deliveryCharge, $courierName) {
            $updateData = [
                'status' => $newStatus,
            ];

            if ($deliveryCharge !== null) {
                $deliveryChargeVal = (float) $deliveryCharge;
                $updateData['delivery_charge'] = $deliveryChargeVal;
                $updateData['grand_total'] = (float) $order->total_amount - (float) $order->discount_amount + (float) $order->tax_amount + $deliveryChargeVal;
            }

            if ($newStatus === OrderStatusEnum::READY_TO_DELIVER && ! empty($courierName)) {
                $updateData['parcel_booking_status'] = 'processing';
                $updateData['latest_courier_provider'] = strtolower($courierName);
            }

            $order->update($updateData);

            $finalNote = $note;
            if ($courierName) {
                $courierNote = 'Courier: '.ucfirst($courierName);
                $finalNote = $finalNote ? ($finalNote.' | '.$courierNote) : $courierNote;
            }

            $order->statusHistories()->create([
                'status' => $newStatus,
                'note' => $finalNote,
                'changed_by_id' => $userId,
            ]);

            if ($newStatus === OrderStatusEnum::READY_TO_DELIVER && ! empty($courierName)) {
                CreateCourierParcelJob::dispatch($order, $courierName);
            }

            return $order;
        });

        $trackingContext = [
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ];

        event(new OrderStatusUpdatedEvent($updatedOrder, $currentStatus, $newStatus, $trackingContext));

        return $updatedOrder;
    }

    private function getStatusRank(OrderStatusEnum $status): int
    {
        return match ($status) {
            OrderStatusEnum::PLACED => 1,
            OrderStatusEnum::CONFIRMED => 2,
            OrderStatusEnum::PACKAGING => 3,
            OrderStatusEnum::READY_TO_DELIVER => 4,
            OrderStatusEnum::SHIPPED => 5,
            OrderStatusEnum::DELIVERED => 6,
            OrderStatusEnum::CANCELLED => 6,
            OrderStatusEnum::UNREACHABLE => 6,
            OrderStatusEnum::RETURNED => 6,
        };
    }
}
