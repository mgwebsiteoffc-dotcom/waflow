<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class WorkflowVersion extends Model {
 protected $fillable=['workflow_id','version','graph','published_by','published_at'];
 protected $casts=['graph'=>'array','published_at'=>'datetime'];
 public function workflow(){return $this->belongsTo(Workflow::class);}
}
