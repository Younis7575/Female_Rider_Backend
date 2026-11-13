<?php

namespace App\Filament\Widgets;

use App\Models\AuditLog;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class RecentActivity extends TableWidget
{
    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = '30s';

    public static function canView(): bool
    {
        return (bool) auth('admin')->user()?->can('audit.view');
    }

    public function table(Table $table): Table
    {
        return $table->heading('Recent activity')
            ->query(AuditLog::query()->latest('id'))
            ->defaultPaginationPageOption(8)
            ->columns([
                TextColumn::make('created_at')->since(),
                TextColumn::make('action')->badge(),
                TextColumn::make('actor')->state(fn (AuditLog $record) => $record->actorName()),
                TextColumn::make('subject')->state(fn (AuditLog $record) => $record->auditable_type ? class_basename($record->auditable_type).' #'.$record->auditable_id : '—'),
            ]);
    }
}
