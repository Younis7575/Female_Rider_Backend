<?php

namespace App\Filament\Resources\Payments;

use App\Filament\Concerns\AuthorizesWithPermission;
use App\Filament\Resources\Trips\TripResource;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Services\Finance\PaymentService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class PaymentResource extends Resource
{
    use AuthorizesWithPermission;

    protected static ?string $model = Payment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 1;

    protected static string $viewPermission = 'finance.view';

    protected static ?string $managePermission = 'finance.refunds.approve';

    protected static bool $allowCreate = false;

    protected static bool $allowEdit = false;

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->modifyQueryUsing(fn ($query) => $query->with('trip', 'payer'))->columns([
            TextColumn::make('created_at')->dateTime('d M Y H:i')->sortable(),
            TextColumn::make('uuid')->label('Payment')->limit(8)->fontFamily('mono')->copyable(),
            TextColumn::make('payer.name')->label('Customer')->searchable(),
            TextColumn::make('purpose')->badge()->color('gray'),
            TextColumn::make('method')->badge(),
            TextColumn::make('amount')->money('PKR')->sortable()->summarize(Sum::make()->money('PKR')),
            TextColumn::make('refunded_amount')->money('PKR')->toggleable(),
            TextColumn::make('status')->badge()->color(fn ($state) => match ($state) {
                'paid' => 'success', 'failed' => 'danger', 'refunded', 'partially_refunded' => 'warning', default => 'gray'
            }),
            TextColumn::make('paid_at')->since()->placeholder('—'),
        ])->filters([
            SelectFilter::make('status')->options(['pending' => 'Pending', 'paid' => 'Paid', 'failed' => 'Failed', 'refunded' => 'Refunded', 'partially_refunded' => 'Partially refunded']),
            SelectFilter::make('method')->options(['cash' => 'Cash', 'card' => 'Card', 'wallet' => 'Wallet']),
            SelectFilter::make('purpose')->options(['fare' => 'Fare', 'cancellation_fee' => 'Cancellation fee']),
        ])->recordActions([
            Action::make('trip')->icon(Heroicon::OutlinedMapPin)->color('gray')->url(fn (Payment $record) => TripResource::getUrl('view', ['record' => $record->trip])),
            Action::make('reconcileCash')->label('Mark cash received')->icon(Heroicon::OutlinedCheck)->color('success')
                ->visible(fn (Payment $record) => $record->status === 'pending' && $record->method === 'cash' && static::canManage())
                ->requiresConfirmation()->modalDescription('Use only after confirming the cash was collected (e.g. driver could not confirm in-app). This posts ledger entries.')
                ->action(function (Payment $record) {
                    app(PaymentService::class)->markPaid($record, 'admin', auth('admin')->id());
                    AuditLog::record('payment.cash_reconciled', $record);
                    Notification::make()->title('Payment reconciled')->success()->send();
                }),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListPayments::route('/')];
    }
}
