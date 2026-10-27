<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Workflow extends Model {
 protected $fillable=['shop_id','name','description','enabled','draft_graph','published_version_id'];
 protected $casts=['enabled'=>'boolean','draft_graph'=>'array'];
 public function shop(){return $this->belongsTo(Shop::class);}
 public function versions(){return $this->hasMany(WorkflowVersion::class);}
 public function publishedVersion(){return $this->belongsTo(WorkflowVersion::class,'published_version_id');}
 public function executions(){return $this->hasMany(WorkflowExecution::class);}
}
