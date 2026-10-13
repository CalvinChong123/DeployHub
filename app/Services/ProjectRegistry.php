<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class ProjectRegistry
{
    public static function separateTargets(): void
    {
        foreach (DB::table('projects')->orderBy('id')->get() as $project) {
            $environments = DB::table('environments')->where('project_id', $project->id)->orderBy('id')->get();
            if ($environments->count() < 2) {
                continue;
            }
            $members = DB::table('project_user')->where('project_id', $project->id)->pluck('user_id');
            foreach ($environments as $index => $env) {
                $name = mb_substr($project->name.' · '.$env->name, 0, 100);
                $id = $project->id;
                if ($index === 0) {
                    DB::table('projects')->where('id', $id)->update(['name' => $name, 'updated_at' => now()]);
                } else {
                    $id = DB::table('projects')->insertGetId(['workspace_id' => $project->workspace_id, 'name' => $name,
                        'repository' => $project->repository, 'description' => $project->description, 'created_at' => $project->created_at, 'updated_at' => now()]);
                    foreach ($members as $user) {
                        DB::table('project_user')->insert(['project_id' => $id, 'user_id' => $user]);
                    }
                    DB::table('environments')->where('id', $env->id)->update(['project_id' => $id]);
                }
                Audit::record($project->workspace_id, null, 'project.target_separated', 'project:'.$id, ['source_project_id' => $project->id, 'environment_id' => $env->id]);
            }
        }
    }
}
