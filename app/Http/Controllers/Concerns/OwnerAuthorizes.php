<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Model;

trait OwnerAuthorizes
{
    /**
     * Abort 403 if the current user does not own the model.
     *
     * Auto-detects owner column (prefers 'user_id', then 'created_by') unless you pass $ownerColumn.
     */
    protected function authorizeOwner(Model $model, ?string $ownerColumn = null): void
    {
        $userId = auth()->id();
        if (!$userId) abort(401, 'Unauthenticated');

        $column = $ownerColumn ?? $this->detectOwnerColumn($model);
        if (!$column || !array_key_exists($column, $model->getAttributes())) {
            abort(500, "Owner column not found on model: {$model->getTable()}");
        }

        if ((int) $model->getAttribute($column) !== (int) $userId) {
            abort(403, 'Forbidden');
        }
    }

    /**
     * Choose sensible default owner column.
     */
    private function detectOwnerColumn(Model $model): ?string
    {
        $attrs = array_keys($model->getAttributes());
        if (in_array('user_id', $attrs, true)) return 'user_id';
        if (in_array('created_by', $attrs, true)) return 'created_by';
        return null;
    }
}
