<?php

namespace App\Filament\Widgets;

use App\Models\Trip;
use Carbon\Carbon;
use Filament\Widgets\ChartWidget;

class RevenueChart extends ChartWidget
{
    protected static ?int $sort = 3;

    protected ?string $heading = 'Gross bookings vs commission (PKR)';

    protected ?string $maxHeight = '280px';

    public static function canView(): bool
    {
        return (bool) auth('admin')->user()?->can('finance.view');
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $days = collect(range(13, 0))->map(fn ($d) => now()->subDays($d)->toDateString());
        $rows = Trip::query()->where('status', 'completed')->where('completed_at', '>=', now()->subDays(14)->startOfDay())
            ->selectRaw('DATE(completed_at) d, SUM(final_fare) g, SUM(commission_amount) c')->groupBy('d')->get()->keyBy('d');

        return [
            'datasets' => [
                ['label' => 'Gross bookings', 'data' => $days->map(fn ($d) => round((float) ($rows[$d]->g ?? 0)))->all(), 'backgroundColor' => '#C8102E', 'borderRadius' => 6],
                ['label' => 'Commission', 'data' => $days->map(fn ($d) => round((float) ($rows[$d]->c ?? 0)))->all(), 'backgroundColor' => '#F4A7B4', 'borderRadius' => 6],
            ],
            'labels' => $days->map(fn ($d) => Carbon::parse($d)->format('d M'))->all(),
        ];
    }
}
