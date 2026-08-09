<?php

namespace App\Concerns;

use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use App\Models\SchoolParent;

trait HasParentLinking
{
    /**
     * Generate a unique linking code for parent association.
     */
    public function generateLinkingCode(): string
    {
        do {
            // Generate a 6-character alphanumeric code
            $code = strtoupper(Str::random(6));
            
            // Ensure it doesn't already exist in the pivot table
            $exists = \DB::table('parent_student')
                ->where('link_code', $code)
                ->exists();
                
        } while ($exists);

        return $code;
    }

    /**
     * Link a parent to this student with a unique code.
     */
    public function linkParent(
        SchoolParent $parent, 
        string $relationshipType = 'parent', 
        bool $isPrimary = false
    ): array {
        // Check if student already has 2 parents
        if ($this->parents()->count() >= 2) {
            throw new \Exception('Student can only be linked to a maximum of 2 parents.');
        }

        // Check if parent can be linked to more students
        if (!$parent->canLinkMoreStudents()) {
            throw new \Exception('Parent has reached the maximum number of linked students.');
        }

        // Check if this parent is already linked to this student
        if ($this->parents()->where('parent_id', $parent->id)->exists()) {
            throw new \Exception('This parent is already linked to this student.');
        }

        // If this is set as primary, unset other primary parents for this student
        if ($isPrimary) {
            $this->parents()->updateExistingPivot(
                $this->parents()->get()->pluck('id')->toArray(),
                ['is_primary' => false]
            );
        }

        $linkCode = $this->generateLinkingCode();

        // Attach the parent with the link code
        $this->parents()->attach($parent->id, [
            'link_code' => $linkCode,
            'relationship_type' => $relationshipType,
            'is_primary' => $isPrimary,
            'linked_at' => now(),
        ]);

        return [
            'link_code' => $linkCode,
            'message' => "Parent successfully linked to student with code: {$linkCode}"
        ];
    }

    /**
     * Unlink a parent from this student.
     */
    public function unlinkParent(SchoolParent $parent): bool
    {
        return $this->parents()->detach($parent->id) > 0;
    }

    /**
     * Link a parent using a linking code.
     */
    public static function linkParentByCode(
        string $linkCode, 
        SchoolParent $parent, 
        string $relationshipType = 'parent',
        bool $isPrimary = false
    ): array {
        // Find the student with the linking code
        $pivotData = \DB::table('parent_student')
            ->where('link_code', $linkCode)
            ->whereNull('parent_id')
            ->first();

        if (!$pivotData) {
            throw new \Exception('Invalid or expired linking code.');
        }

        $student = static::findOrFail($pivotData->student_id);

        // Update the pivot record with parent information
        \DB::table('parent_student')
            ->where('link_code', $linkCode)
            ->update([
                'parent_id' => $parent->id,
                'relationship_type' => $relationshipType,
                'is_primary' => $isPrimary,
                'linked_at' => now(),
                'updated_at' => now(),
            ]);

        return [
            'student' => $student,
            'message' => "Parent successfully linked to {$student->full_name}"
        ];
    }

    /**
     * Create a pending parent link (generates code without parent).
     */
    public function createPendingParentLink(): string
    {
        // Check if student already has 2 parents
        if ($this->parents()->count() >= 2) {
            throw new \Exception('Student already has the maximum number of parents.');
        }

        $linkCode = $this->generateLinkingCode();

        // Create a pending link record
        \DB::table('parent_student')->insert([
            'student_id' => $this->id,
            'parent_id' => null,
            'link_code' => $linkCode,
            'relationship_type' => null,
            'is_primary' => false,
            'linked_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $linkCode;
    }

    /**
     * Get pending link codes for this student.
     */
    public function getPendingLinkCodes(): array
    {
        return \DB::table('parent_student')
            ->where('student_id', $this->id)
            ->whereNull('parent_id')
            ->pluck('link_code')
            ->toArray();
    }

    /**
     * Remove expired pending link codes (older than 30 days).
     */
    public static function cleanupExpiredLinkCodes(): int
    {
        return \DB::table('parent_student')
            ->whereNull('parent_id')
            ->where('created_at', '<', now()->subDays(30))
            ->delete();
    }

    /**
     * Get the primary parent for this student.
     */
    public function primaryParent()
    {
        return $this->parents()->wherePivot('is_primary', true)->first();
    }

    /**
     * Get all non-primary parents for this student.
     */
    public function secondaryParents()
    {
        return $this->parents()->wherePivot('is_primary', false);
    }

    /**
     * Set a parent as primary for this student.
     */
    public function setPrimaryParent(SchoolParent $parent): void
    {
        if (!$this->parents()->where('parent_id', $parent->id)->exists()) {
            throw new \Exception('Parent is not linked to this student.');
        }

        // Unset all primary flags
        $this->parents()->updateExistingPivot(
            $this->parents()->get()->pluck('id')->toArray(),
            ['is_primary' => false]
        );

        // Set the specified parent as primary
        $this->parents()->updateExistingPivot($parent->id, ['is_primary' => true]);
    }
}