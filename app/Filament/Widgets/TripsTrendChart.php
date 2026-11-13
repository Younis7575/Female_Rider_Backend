<?php

namespace App\Filament\Widgets;

use App\Models\Trip;
use Carbon\Carbon;
use Filament\Widgets\ChartWidget;

class TripsTrendChart extends ChartWidget
{
    protected static ?int $sort = 2;

    protected ?string $heading = 'Trips — last 14 days';

    protected ?string $maxHeight = '280px';

    public static function canView(): bool
    {
        return (bool) auth('admin')->user()?->can('dashboard.view');
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $days = collect(range(13, 0))->map(fn ($d) => now()->subDays($d)->toDateString());
        $count = fn (array $statuses, string $col) => Trip::query()->whereIn('status', $statuses)->where($col, '>=', now()->subDays(14)->startOfDay())
            ->selectRaw("DATE($col) d, COUNT(*) c")->groupBy('d')->pluck('c', 'd');
        $completed = $count(['completed'], 'completed_at');
        $cancelled = $count(['cancelled_by_customer', 'cancelled_by_driver', 'cancelled_by_admin'], 'cancelled_at');

        return [
            'datasets' => [
                ['label' => 'Completed', 'data' => $days->map(fn ($d) => (int) ($completed[$d] ?? 0))->all(), 'borderColor' => '#C8102E', 'backgroundColor' => 'rgba(200,16,46,.12)', 'fill' => true, 'tension' => 0.35],
                ['label' => 'Cancelled', 'data' => $days->map(fn ($d) => (int) ($cancelled[$d] ?? 0))->all(), 'borderColor' => '#71717A', 'backgroundColor' => 'rgba(113,113,122,.08)', 'fill' => true, 'tension' => 0.35],
            ],
            'labels' => $days->map(fn ($d) => Carbon::parse($d)->format('d M'))->all(),
        ];
    }
}
