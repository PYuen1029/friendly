<?php

namespace GridPrinciples\Friendly\Traits;

use GridPrinciples\Friendly\FriendPivot;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Collection;

trait Friendly
{
    /**
     * Connect the parent model with another model, AKA "send friend request".
     *
     * @param $model
     * @param array $pivot
     * @return mixed
     */
    public function befriend($model, $pivot = [])
    {
        return $this->sentRequests()->save($model, $pivot);
    }

    /**
     * Remove the connection between these two models.  AKA "block user".
     *
     * @param $model
     * @return bool
     */
    public function block($model)
    {
        $deletedAtLeastOne = false;

        if ($this->friends->count()) {
            foreach ($this->friends as $friend) {
                if ($friend->getKey() == $model->id) {
                    $friend->pivot->delete();
                    $this->resetFriends();

                    $deletedAtLeastOne = true;
                }
            }
        }

        return $deletedAtLeastOne;
    }

    /**
     * Approve an incoming connection request.  AKA "approve request"
     *
     * @param $model
     * @return bool
     */
    public function approve($model)
    {
        $approvedAtLeastOne = false;

        if ($model->friends->count()) {
            foreach ($model->friends as $friend) {
                if ((int) $friend->getKey() === (int) $this->getKey()) {
                    $friend->pivot->approved_at = new \Carbon\Carbon;
                    $friend->pivot->save();
                    $this->resetFriends();

                    $approvedAtLeastOne = true;
                }
            }
        }

        return $approvedAtLeastOne;
    }

    /**
     * Approve an incoming connection request.  AKA "approve request"
     *
     * @param $model
     * @return bool
     */
    public function deny($model)
    {
        $deniedAtLeastOne = false;

        if ($model->friends->count()) {
            foreach ($model->friends as $friend) {
                if ((int) $friend->getKey() === (int) $this->getKey()) {
                    $friend->pivot->delete();
                    $this->resetFriends();

                    $deniedAtLeastOne = true;
                }
            }
        }

        return $deniedAtLeastOne;
    }

    /**
     * Sort of acts like a relationship.  Actually just gets two relations which are collected together. ACCESSOR
     *
     * @return mixed
     */
    public function getFriendsAttribute()
    {
        // so this is asking if 'friends' is a key in $this->relations
        if (!array_key_exists('friends', $this->relations)) {
            $this->loadFriends();
        }

        return $this->getRelation('friends');
    }

    /**
     * Filters the primary connections by ones that are currently active.
     *
     * @return mixed
     */
    public function getCurrentFriendsAttribute()
    {
        return $this->friends->filter(function ($item) {
            $now = new \Carbon\Carbon;

            if(!$item->pivot->approved_at)
            {
                return false;
            }

            switch (true) {
                // no dates set
                case !$item->pivot->end && !$item->pivot->start:

                    // start is set but is in the future
                case !$item->pivot->end && $item->pivot->start && $item->pivot->start < $now:

                    // end is set but is in the past
                case !$item->pivot->start && $item->pivot->end && $item->pivot->end > $now:

                    // both start and end are set, but we are currently between those dates
                case $item->pivot->start && $item->pivot->start < $now && $item->pivot->end && $item->pivot->end > $now:

                    return true;
                    break;
            }

            // any other scenario fails
            return false;
        });
    }
    /**
     * Checks if Auth::user() is friends with $user
     * @param  App\User  $model 
     * @return boolean
     */
    public function isFriendsWith($model)
    {
        $isFriends = false;

        if ($model->friends->count()) {
            foreach ($model->friends as $friend) {
                if ((int) $friend->getKey() === (int) $this->getKey()){
                    $isFriends = true;
                }
            }
        }

        return $isFriends;
    }

    /**
     * Eloquent relation defining connections this model initiated.
     *
     * @return mixed
     */
    public function sentRequests()
    {
        return $this->belongsToMany(get_called_class(), 'friends', 'user_id', 'other_user_id')
            ->withPivot('name', 'other_name', 'start', 'end', 'approved_at')
            ->whereNull('friends.deleted_at')
            ->withTimestamps();
    }

    /**
     * Eloquent relationship defining incoming connection requests.
     *
     * @return mixed
     */
    public function receivedApprovedRequests()
    {
        // The third argument is the foreign key name of the model on which you are defining the relationship, while the fourth argument is the foreign key name of the model that you are joining to
        return $this->belongsToMany(get_called_class(), 'friends', 'other_user_id', 'user_id')
        // Set the columns on the pivot table to retrieve.
            ->withPivot('name', 'other_name', 'start', 'end', 'approved_at')
            ->whereNull('friends.deleted_at')
            ->whereNotNull('friends.approved_at')
            ->withTimestamps();
    }

    /**
     * Eloquent relationship defining incoming connection requests.
     *
     * @return mixed
     */
    public function receivedPendingRequests()
    {
        // The third argument is the foreign key name of the model on which you are defining the relationship, while the fourth argument is the foreign key name of the model that you are joining to
        return $this->belongsToMany(get_called_class(), 'friends', 'other_user_id', 'user_id')
        // Set the columns on the pivot table to retrieve.
            ->withPivot('id', 'name', 'other_name', 'start', 'end', 'approved_at')
            ->whereNull('friends.deleted_at')
            ->whereNull('friends.approved_at')
            ->withTimestamps();
    }

    /**
     * Reset the cached connections so they can be rebuilt next time they are requested.
     */
    public function resetFriends()
    {
        unset($this->relations['friends']);
        unset($this->relations['sentRequests']);
        unset($this->relations['receivedApprovedRequests']);
    }

    /**
     * Load and cache the connections.
     */
    protected function loadFriends()
    {
        if (!array_key_exists('friends', $this->relations)) {
            $this->setRelation('friends', $this->mergeMineAndRequestedFriends());
        }
    }

    /**
     * Merge the result of two relationships.
     * @return mixed
     */
    protected function mergeMineAndRequestedFriends()
    {
        return $this->sentRequests->merge($this->receivedApprovedRequests);
    }

    public function newPivot(Model $parent, array $attributes, $table, $exists)
    {
        return new FriendPivot($parent, $attributes, $table, $exists);
    }

    public function getAllFriendsBlogPosts()
    {
        // create an empty Collection
        $allBlogPosts = new Collection;

        // foreach through $this->friends as $friend
        foreach ($this->friends as $friend){
   
            // add $friend->blog->posts() Collection to $allBlogPosts
            $allBlogPosts->add($friend->blog->blogPost()->getResults());

        }

        $allBlogPosts = $allBlogPosts->collapse();

        return $allBlogPosts->sortByDesc(function($item){
            return $item->updated_at;
        });
    }
}
