<?php
require_once __DIR__ . '/../includes/role_layout.php';
require_role(['staff']);
const STAFF_SERVICE_CATEGORIES = [
    'Preventive & Routine Care',
    'Vaccinations & Immunization',
    'Grooming & Hygiene',
    'Surgery & Minor Procedures',
    'Diagnostics & Laboratory Services',
    'Other',
];
$pdo=db();$error=$success='';
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
 if(!csrf_valid(posted_string('csrf'))){
  $error='Your session expired.';
 } else {
  $action=posted_string('action');
  $id=(int)posted_string('service_id');
  $name=trim(posted_string('service_name'));
  $category=posted_string('service_category');
  $price=(float)posted_string('base_price');
  $duration=(int)posted_string('duration_minutes');
  $description=trim(posted_string('description'));
  $active=posted_string('is_active')==='1'?1:0;
  try{
   if($action==='save' && ($name===''||!in_array($category,STAFF_SERVICE_CATEGORIES,true)||$price<0||$duration<15))throw new InvalidArgumentException('Enter a valid name, category, price and duration.');
   $pdo->beginTransaction();
   if($action==='save'){
    if($id){
     $pdo->prepare('UPDATE Services SET service_name=?,service_category=?,base_price=?,duration_minutes=?,description=?,is_active=? WHERE service_id=?')->execute([$name,$category,$price,$duration,$description?:null,$active,$id]);
    } else {
     $pdo->prepare('INSERT INTO Services(service_name,service_category,base_price,duration_minutes,description,is_active) VALUES(?,?,?,?,?,?)')->execute([$name,$category,$price,$duration,$description?:null,$active]);
     $id=(int)$pdo->lastInsertId();
     $pdo->prepare('INSERT IGNORE INTO VetServices(vet_id,service_id) SELECT vet_id,? FROM Veterinarians')->execute([$id]);
    }
    role_log($pdo,(int)$_SESSION['user_id'],'service.saved','service',$id,$name);
    $success='Service saved.';
    $pdo->commit();
   }
  }catch(Throwable$ex){
   if($pdo->inTransaction())$pdo->rollBack();
   $error=$ex instanceof InvalidArgumentException?$ex->getMessage():($ex->getCode()==='23000'?'A service with that name already exists.':'The service could not be saved.');
  }
 }
}
role_render('staff',['services'],'Services','Maintain services used in appointment scheduling',function(array$c)use($pdo,$error,$success){
 $rows=$pdo->query("SELECT * FROM Services ORDER BY CASE WHEN service_name='Other / Request a Quote' THEN 1 ELSE 0 END,service_name")->fetchAll();if($error)echo'<div class="notice error">'.e($error).'</div>';if($success)echo'<div class="notice success">'.e($success).'</div>';?>
 <section class="panel">
   <div class="panel-header">
     <h2>Clinic services</h2>
     <div class="services-header-actions">
       <span class="data-count"><?=count($rows)?> services</span>
       <button class="button small services-add-button" type="button" id="add-service">+ Add service</button>
     </div>
   </div>
   <div class="table-wrap data-table-card">
     <table class="records-table">
         <colgroup><col style="width:17%"><col style="width:20%"><col style="width:25%"><col style="width:12%"><col style="width:10%"><col style="width:8%"><col></colgroup>
         <thead><tr><th>Service</th><th>Category</th><th>Description</th><th class="col-right">Price</th><th class="col-center">Duration</th><th>Status</th><th>Action</th></tr></thead>
       <tbody><?php foreach($rows as$i=>$r):?>
         <tr<?=$i%2?' class="alt"':''?>>
           <td data-label="Service"><?=e($r['service_name'])?></td>
           <td data-label="Category"><?=e($r['service_category'])?></td>
           <td data-label="Description"><?=e($r['description']?:'—')?></td>
           <td data-label="Price" class="col-right">₱<?=number_format((float)$r['base_price'],2)?></td>
           <td data-label="Duration" class="col-center"><?=$r['duration_minutes']?> min</td>
           <td data-label="Status"><?= $r['is_active']?'Active':'Inactive' ?></td>
           <td data-label="Action"><button class="button small secondary edit-service" type="button" data-id="<?=$r['service_id']?>" data-name="<?=e($r['service_name'])?>" data-category="<?=e($r['service_category'])?>" data-price="<?=e($r['base_price'])?>" data-duration="<?=$r['duration_minutes']?>" data-description="<?=e($r['description']?:'')?>" data-active="<?=$r['is_active']?>">Edit</button></td>
         </tr>
       <?php endforeach?></tbody>
     </table>
   </div>
 </section>
 <dialog id="service-dialog" aria-labelledby="service-title">
   <div class="dialog-head service-dialog-head">
     <h2 id="service-title">Add service</h2>
     <button class="dialog-close" type="button" aria-label="Close dialog">×</button>
   </div>
   <form class="dialog-form" id="service-form" method="post">
     <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
     <input type="hidden" name="action" value="save">
     <input type="hidden" name="service_id" id="service-id" value="0">
     <div class="form-grid service-form-grid">
       <label class="field service-field-full">Service name<input name="service_name" id="service-name" required maxlength="100"></label>
       <label class="field service-field-full">Category<select name="service_category" id="service-category" required><?php foreach(STAFF_SERVICE_CATEGORIES as $category):?><option value="<?=e($category)?>"><?=e($category)?></option><?php endforeach?></select></label>
       <label class="field">Base price<input name="base_price" id="service-price" type="number" min="0" step="0.01" required></label>
       <label class="field">Duration (minutes)<input name="duration_minutes" id="service-duration" type="number" min="15" max="480" required></label>
       <label class="field service-field-full">Description<textarea name="description" id="service-description" maxlength="255"></textarea></label>
       <label class="field">Status<select name="is_active" id="service-active"><option value="1">Active</option><option value="0">Inactive</option></select></label>
     </div>
     <div class="form-actions service-form-actions">
       <button class="button secondary service-cancel" type="button">Cancel</button>
       <button class="button" type="submit">Save service</button>
     </div>
   </form>
 </dialog>
 <style>
   .services-header-actions{display:flex;align-items:center;gap:12px}
   .services-add-button{min-height:36px;padding:7px 12px;color:#064465;background:#fff;border-color:#fff;white-space:nowrap}
   .services-add-button:hover{color:#04334d;background:#eef8ff;border-color:#eef8ff}
   .service-dialog-head{display:flex;align-items:center;justify-content:space-between;gap:12px}
   .dialog-close{width:34px;height:34px;padding:0;color:#fff;background:transparent;border:1px solid rgba(255,255,255,.55);border-radius:8px;font-size:1.35rem;line-height:1;cursor:pointer}
   .dialog-close:hover{background:rgba(255,255,255,.15)}
   .service-form-grid{align-items:start;gap:16px 18px}
   .service-form-grid .service-field-full{grid-column:1/-1}
   .service-form-actions{margin-top:2px}
   @media(max-width:600px){.service-form-grid{gap:13px}}
   @media(max-width:480px){.services-header-actions{gap:7px}.services-header-actions .data-count{font-size:.72rem}.services-add-button{padding:6px 8px;font-size:.74rem}}
 </style>
 <script>
   (function(){
     var dialog=document.getElementById('service-dialog');
     var form=document.getElementById('service-form');
     var title=document.getElementById('service-title');
     var idInput=document.getElementById('service-id');

     function closeService(){
       if(form.dataset.dirty==='1'&&!window.confirm('Discard unsaved service changes?'))return;
       dialog.close();
     }
     function resetServiceForm(){
       form.reset();
       form.dataset.dirty='0';
       idInput.value='0';
       title.textContent='Add service';
     }
     function openService(){
       resetServiceForm();
       dialog.showModal();
     }

     document.getElementById('add-service').addEventListener('click',openService);
     document.querySelectorAll('.edit-service').forEach(function(button){
       button.addEventListener('click',function(){
         resetServiceForm();
         title.textContent='Edit service';
         idInput.value=button.dataset.id;
         document.getElementById('service-name').value=button.dataset.name;
         document.getElementById('service-category').value=button.dataset.category;
         document.getElementById('service-price').value=button.dataset.price;
         document.getElementById('service-duration').value=button.dataset.duration;
         document.getElementById('service-description').value=button.dataset.description;
         document.getElementById('service-active').value=button.dataset.active;
         dialog.showModal();
       });
     });
     form.addEventListener('input',function(){form.dataset.dirty='1'});
     form.addEventListener('change',function(){form.dataset.dirty='1'});
     form.addEventListener('submit',function(){form.dataset.dirty='0'});
     dialog.querySelector('.dialog-close').addEventListener('click',closeService);
     dialog.querySelector('.service-cancel').addEventListener('click',closeService);
     dialog.addEventListener('cancel',function(event){
       if(form.dataset.dirty==='1'){
         event.preventDefault();
         closeService();
       }
     });
   })();
 </script><?php
});