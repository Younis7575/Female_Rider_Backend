<?php

namespace App\Filament\Resources\Payouts;

use App\Exceptions\BusinessRuleException;
use App\Filament\Concerns\AuthorizesWithPermission;
use App\Models\DriverPayout;
use App\Services\Finance\LedgerService;
use App\Services\Finance\PayoutService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class PayoutResource extends Resource
{
    use AuthorizesWithPermission;

    protected static ?string $model = DriverPayout::class;

    protected static ?string $modelLabel = 'Driver payout';

    protected static ?string $navigationLabel = 'Driver payouts';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWallet;

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 3;

    protected static string $viewPermission = 'finance.view';

    protected static ?string $managePermission = 'finance.payouts.approve';

    protected static bool $allowCreate = false;

    protected static bool $allowEdit = false;

    public static function getNavigationBadge(): ?string
    {
        $n = DriverPayout::query()->whereIn('status', ['requested', 'approved', 'processing'])->count();

        return $n ? (string) $n : null;
    }

    public static function table(Table $table): Table
    {
        $threshold = (float) config('femalerider.finance.maker_checker_threshold');
        $wrap = function (callable $fn, string $msg) {
            try {
                $fn();
                Notification::make()->title($msg)->success()->send();
            } catch (BusinessRuleException $e) {
                Notification::make()->title('Not allowed')->body($e->getMessage())->danger()->send();
            }
        };

        return $table->defaultSort('id', 'desc')->modifyQueryUsing(fn ($query) => $query->with('driver', 'maker', 'checker'))->columns([
            TextColumn::make('created_at')->dateTime('d M Y H:i'),
            TextColumn::make('driver.name')->label('Driver')->searchable()->description(fn ($record) => 'Ledger balance: PKR '.number_format(app(LedgerService::class)->driverBalance($record->driver_id), 2)),
            TextColumn::make('amount')->money('PKR')->description(fn ($record) => $record->amount > $threshold ? 'Requires 2 approvers' : null),
            TextColumn::make('method')->badge()->description(fn ($record) => $record->account_title.' · '.$record->account_number_masked),
            TextColumn::make('status')->badge()->color(fn ($state) => match ($state) {
                'paid' => 'success', 'rejected', 'failed' => 'danger', 'processing' => 'info', default => 'warning'
            }),
            TextColumn::make('maker.name')->label('Maker')->placeholder('—'),
            TextColumn::make('checker.name')->label('Checker')->placeholder('—'),
            TextColumn::make('provider_ref')->label('Reference')->placeholder('—'),
        ])->filters([SelectFilter::make('status')->options(['requested' => 'Requested', 'approved' => 'Awaiting 2nd approval', 'processing' => 'Approved – to pay', 'paid' => 'Paid', 'rejected' => 'Rejected'])])
            ->recordActions([
                Action::make('approve')->icon(Heroicon::OutlinedCheck)->color('success')
                    ->label(fn ($record) => $record->status === 'approved' ? 'Second approval' : 'Approve')
                    ->visible(fn ($record) => in_array($record->status, ['requested', 'approved'], true) && static::canManage())
                    ->requiresConfirmation()->schema([Textarea::make('notes')])
                    ->action(fn ($record, array $data) => $wrap(fn () => app(PayoutService::class)->approve(auth('admin')->user(), $record, $data['notes'] ?? null), 'Payout approved')),
                Action::make('markPaid')->label('Mark paid')->icon(Heroicon::OutlinedBanknotes)->color('primary')
                    ->visible(fn ($record) => $record->status === 'processing' && static::canManage())
                    ->schema([TextInput::make('reference')->required()->label('Bank / wallet transaction reference')])
                    ->requiresConfirmation()
                    ->action(fn ($record, array $data) => $wrap(fn () => app(PayoutService::class)->markPaid(auth('admin')->user(), $record, $data['reference']), 'Payout marked paid')),
                Action::make('reject')->icon(Heroicon::OutlinedXMark)->color('danger')
                    ->visible(fn ($record) => in_array($record->status, ['requested', 'approved', 'processing'], true) && static::canManage())
                    ->schema([Textarea::make('reason')->required()->label('Reason (shown to driver)')])
                    ->action(fn ($record, array $data) => $wrap(fn () => app(PayoutService::class)->reject(auth('admin')->user(), $record, $data['reason']), 'Payout rejected')),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListPayouts::route('/')];
    }
}
