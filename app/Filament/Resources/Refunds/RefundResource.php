<?php

namespace App\Filament\Resources\Refunds;

use App\Exceptions\BusinessRuleException;
use App\Filament\Concerns\AuthorizesWithPermission;
use App\Models\Refund;
use App\Services\Finance\RefundService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class RefundResource extends Resource
{
    use AuthorizesWithPermission;

    protected static ?string $model = Refund::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptRefund;

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 2;

    protected static string $viewPermission = 'finance.view';

    protected static ?string $managePermission = 'finance.refunds.approve';

    protected static bool $allowCreate = false;

    protected static bool $allowEdit = false;

    public static function getNavigationBadge(): ?string
    {
        $n = Refund::query()->whereIn('status', ['requested', 'approved'])->count();

        return $n ? (string) $n : null;
    }

    public static function table(Table $table): Table
    {
        $threshold = (float) config('femalerider.finance.maker_checker_threshold');
        $wrap = function (callable $fn) {
            try {
                $fn();
                Notification::make()->title('Refund updated')->success()->send();
            } catch (BusinessRuleException $e) {
                Notification::make()->title('Not allowed')->body($e->getMessage())->danger()->send();
            }
        };

        return $table->defaultSort('id', 'desc')->modifyQueryUsing(fn ($query) => $query->with('user', 'maker', 'checker'))->columns([
            TextColumn::make('created_at')->dateTime('d M Y H:i'),
            TextColumn::make('user.name')->label('Customer')->searchable(),
            TextColumn::make('amount')->money('PKR')->description(fn ($record) => $record->amount > $threshold ? 'Requires 2 approvers' : null),
            TextColumn::make('reason')->limit(40)->wrap(),
            TextColumn::make('status')->badge()->color(fn ($state) => match ($state) {
                'processed' => 'success', 'rejected' => 'danger', 'approved' => 'info', default => 'warning'
            }),
            TextColumn::make('maker.name')->label('Maker')->placeholder('—'),
            TextColumn::make('checker.name')->label('Checker')->placeholder('—'),
        ])->filters([SelectFilter::make('status')->options(['requested' => 'Requested', 'approved' => 'Awaiting 2nd approval', 'processed' => 'Processed', 'rejected' => 'Rejected'])])
            ->recordActions([
                Action::make('approve')->icon(Heroicon::OutlinedCheck)->color('success')
                    ->label(fn (Refund $record) => $record->status === 'approved' ? 'Second approval' : 'Approve')
                    ->visible(fn (Refund $record) => in_array($record->status, ['requested', 'approved'], true) && static::canManage())
                    ->schema([Textarea::make('notes')])->requiresConfirmation()
                    ->action(fn (Refund $record, array $data) => $wrap(fn () => app(RefundService::class)->approve(auth('admin')->user(), $record, $data['notes'] ?? null))),
                Action::make('reject')->icon(Heroicon::OutlinedXMark)->color('danger')
                    ->visible(fn (Refund $record) => in_array($record->status, ['requested', 'approved'], true) && static::canManage())
                    ->schema([Textarea::make('notes')->required()->label('Reason (shown to customer)')])
                    ->action(fn (Refund $record, array $data) => $wrap(fn () => app(RefundService::class)->reject(auth('admin')->user(), $record, $data['notes']))),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListRefunds::route('/')];
    }
}
