<?php

namespace App\Filament\Resources\EligibilityReviews;

use App\Enums\EligibilityStatus;
use App\Filament\Concerns\AuthorizesWithPermission;
use App\Models\EligibilityVerification;
use App\Services\Driver\EligibilityService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use UnitEnum;

/** Human review queue for women-only eligibility. No automated inference; reviewer decides. */
class EligibilityReviewResource extends Resource
{
    use AuthorizesWithPermission;

    protected static ?string $model = EligibilityVerification::class;

    protected static ?string $modelLabel = 'Eligibility review';

    protected static ?string $navigationLabel = 'Eligibility reviews';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static string|UnitEnum|null $navigationGroup = 'People';

    protected static ?int $navigationSort = 2;

    protected static string $viewPermission = 'eligibility.review';

    protected static ?string $managePermission = 'eligibility.review';

    protected static bool $allowCreate = false;

    protected static bool $allowEdit = false;

    public static function getNavigationBadge(): ?string
    {
        $n = EligibilityVerification::query()->whereIn('status', ['submitted', 'under_review', 'appealed'])->count();

        return $n ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    private static function fileLink(EligibilityVerification $record, ?string $variant, string $label): HtmlString
    {
        $url = route('admin.secure.file', ['type' => 'eligibility', 'id' => $record->id, 'variant' => $variant]);

        return new HtmlString('<a href="'.e($url).'" target="_blank" rel="noopener" class="fi-link" style="color:#C8102E;font-weight:600">'.e($label).' ↗</a> <span style="color:#71717a;font-size:12px">(access is logged)</span>');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Applicant')->columns(3)->schema([
                TextEntry::make('user.name')->label('Name')->weight('bold'),
                TextEntry::make('user.phone')->label('Phone'),
                TextEntry::make('user.created_at')->label('Account created')->dateTime(),
            ]),
            Section::make('Submission')->columns(3)->schema([
                TextEntry::make('status')->badge()->formatStateUsing(fn ($state) => $state->label()),
                TextEntry::make('document_type')->badge()->formatStateUsing(fn ($state) => strtoupper((string) $state)),
                TextEntry::make('submitted_at')->dateTime(),
                TextEntry::make('front')->label('Document (front)')->state(fn ($record) => $record->document_path ? self::fileLink($record, null, 'Open front') : '—'),
                TextEntry::make('back')->label('Document (back)')->state(fn ($record) => $record->document_back_path ? self::fileLink($record, 'back', 'Open back') : '—'),
                TextEntry::make('selfie')->label('Photo')->state(fn ($record) => $record->selfie_path ? self::fileLink($record, 'selfie', 'Open photo') : '—'),
                TextEntry::make('consent_version')->label('Policy version consented'),
                TextEntry::make('appeal_reason')->visible(fn ($record) => filled($record->appeal_reason))->columnSpanFull(),
            ]),
            Section::make('Review')->columns(3)->schema([
                TextEntry::make('reviewer.name')->placeholder('Not reviewed'),
                TextEntry::make('reviewed_at')->dateTime()->placeholder('—'),
                TextEntry::make('user_facing_reason')->label('Reason shown to user')->placeholder('—'),
                TextEntry::make('reviewer_notes')->label('Internal notes (never shown to user)')->placeholder('—')->columnSpanFull(),
            ]),
            Section::make('Reviewer guidance')->collapsible()->collapsed()->schema([
                TextEntry::make('guidance')->hiddenLabel()->state('Verify the document is a genuine government ID matching the account name, and that the holder meets the approved women-only eligibility policy. Do not judge by appearance alone. Escalate ambiguous cases to a senior reviewer. Request more information rather than rejecting when evidence is unclear.'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('submitted_at', 'asc')
            ->modifyQueryUsing(fn ($query) => $query->with('user'))
            ->columns([
                TextColumn::make('user.name')->label('Applicant')->searchable()->weight('bold')->description(fn ($record) => $record->user?->phone),
                TextColumn::make('document_type')->badge()->formatStateUsing(fn ($state) => strtoupper((string) $state)),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => $state->label())
                    ->color(fn ($state) => match ($state) {
                        EligibilityStatus::Approved => 'success', EligibilityStatus::Rejected => 'danger', EligibilityStatus::Appealed => 'info', default => 'warning'
                    }),
                TextColumn::make('submitted_at')->since()->sortable()->label('Waiting'),
                TextColumn::make('reviewer.name')->placeholder('—'),
            ])->filters([
                SelectFilter::make('status')->options(EligibilityStatus::options())->default('submitted'),
            ])->recordActions([ViewAction::make()->label('Review')]);
    }

    public static function viewHeaderActions(): array
    {
        $svc = fn () => app(EligibilityService::class);
        $admin = fn () => auth('admin')->user();
        $pending = fn (EligibilityVerification $record) => in_array($record->status, [EligibilityStatus::Submitted, EligibilityStatus::UnderReview, EligibilityStatus::Appealed], true) && static::canManage();

        return [
            Action::make('startReview')->label('Start review')->icon(Heroicon::OutlinedEye)->color('gray')
                ->visible(fn ($record) => in_array($record->status, [EligibilityStatus::Submitted, EligibilityStatus::Appealed], true) && static::canManage())
                ->action(function ($record) use ($svc, $admin) {
                    $svc()->startReview($admin(), $record);
                    Notification::make()->title('Marked under review')->send();
                }),
            Action::make('approve')->icon(Heroicon::OutlinedCheckBadge)->color('success')->visible($pending)
                ->schema([Textarea::make('notes')->label('Internal notes (optional)')])
                ->requiresConfirmation()->modalDescription('The user will gain Customer access.')
                ->action(function ($record, array $data) use ($svc, $admin) {
                    $svc()->approve($admin(), $record, $data['notes'] ?? null);
                    Notification::make()->title('Approved')->success()->send();
                }),
            Action::make('requestInfo')->label('Request info')->icon(Heroicon::OutlinedChatBubbleLeftEllipsis)->color('warning')->visible($pending)
                ->schema([Textarea::make('reason')->required()->label('What the user must provide (shown to user)'), Textarea::make('notes')->label('Internal notes')])
                ->action(function ($record, array $data) use ($svc, $admin) {
                    $svc()->requestInfo($admin(), $record, $data['reason'], $data['notes'] ?? null);
                    Notification::make()->title('User asked for more information')->send();
                }),
            Action::make('reject')->icon(Heroicon::OutlinedXCircle)->color('danger')->visible($pending)
                ->schema([Textarea::make('reason')->required()->label('Reason (shown to user)'), Textarea::make('notes')->label('Internal notes')])
                ->requiresConfirmation()->modalDescription('The user can appeal this decision.')
                ->action(function ($record, array $data) use ($svc, $admin) {
                    $svc()->reject($admin(), $record, $data['reason'], $data['notes'] ?? null);
                    Notification::make()->title('Rejected')->danger()->send();
                }),
        ];
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListEligibilityReviews::route('/'), 'view' => Pages\ViewEligibilityReview::route('/{record}')];
    }
}
