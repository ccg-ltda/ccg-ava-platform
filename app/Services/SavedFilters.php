<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\SavedFilter;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/**
 * The search criteria a user saves to reuse them. Every query starts from the user and the Workspace of the session
 * (`owned`), so a filter of another user or Workspace does not exist from here (404). Each module (`scope`, in
 * config/saved_filters.php) declares which criteria it accepts; they are validated against the Workspace's own data
 * (a user, chatbot or channel that is not there is refused), and only non-empty criteria are stored.
 */
class SavedFilters
{
    public function __construct(private readonly ChannelCatalog $channels) {}

    /** Whether the module exists. */
    public function known(string $scope): bool
    {
        return array_key_exists($scope, config('saved_filters.scopes'));
    }

    public function permission(string $scope): string
    {
        return config("saved_filters.scopes.{$scope}.permission");
    }

    /** Whether the module exists and the permissions given include the one that opens its page. */
    public function allows(string $scope, array $permissions): bool
    {
        return $this->known($scope) && in_array($this->permission($scope), $permissions, true);
    }

    /** @return Builder<SavedFilter> the filters of this user in this Workspace and module */
    public function owned(User $user, Workspace $workspace, string $scope): Builder
    {
        return SavedFilter::query()->where('workspace_id', $workspace->id)->where('user_id', $user->id)->where('scope', $scope);
    }

    /** @return list<array{id: int, name: string, criteria: array<string, mixed>}> */
    public function for(User $user, Workspace $workspace, string $scope): array
    {
        return $this->owned($user, $workspace, $scope)->orderBy('name')->get()
            ->map(fn (SavedFilter $filter) => ['id' => $filter->id, 'name' => $filter->name, 'criteria' => $filter->criteria])->all();
    }

    public function create(User $user, Workspace $workspace, string $scope, string $name, array $criteria): SavedFilter
    {
        return SavedFilter::create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'scope' => $scope, 'name' => $name, 'criteria' => $criteria]);
    }

    public function atLimit(User $user, Workspace $workspace, string $scope): bool
    {
        return $this->owned($user, $workspace, $scope)->count() >= (int) config('saved_filters.max_per_scope');
    }

    /**
     * Validation rules of the criteria a module accepts (`criteria.<key>`).
     *
     * @return array<string, list<mixed>>
     */
    public function criteriaRules(string $scope, Workspace $workspace): array
    {
        return match ($scope) {
            'audit' => [
                'criteria.search' => ['nullable', 'string', 'max:100'],
                'criteria.from' => ['nullable', 'date_format:Y-m-d'],
                'criteria.to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:criteria.from'],
                'criteria.user' => ['nullable', 'integer', Rule::exists('workspace_user', 'user_id')->where('workspace_id', $workspace->id)],
                'criteria.resource' => ['nullable', Rule::in(array_keys(config('audit.resources')))],
                'criteria.action' => ['nullable', Rule::in(array_keys(config('audit.actions')))],
            ],
            'conversations' => [
                'criteria.q' => ['nullable', 'string', 'max:100'],
                'criteria.status' => ['nullable', Rule::in(Conversation::HANDLING)],
                'criteria.channel' => ['nullable', Rule::in(collect($this->channels->all())->filter(fn (array $c) => $c['conversations'] ?? false)->pluck('key')->all())],
                'criteria.chatbot' => ['nullable', 'integer', Rule::exists('chatbots', 'id')->where('workspace_id', $workspace->id)],
            ],
        };
    }

    /**
     * Keeps only the criteria that have a value (a filter with nothing set is not a filter) and trims the text.
     *
     * @param  array<string, mixed>  $criteria
     * @return array<string, mixed>
     */
    public function clean(array $criteria): array
    {
        return collect($criteria)->map(fn ($value) => is_string($value) ? trim($value) : $value)->filter(fn ($value) => $value !== null && $value !== '')->all();
    }
}
