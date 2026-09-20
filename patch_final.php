<?php
$f = "D:/laragon/www/simad/admin/program_kerja.php";
$t = file_get_contents($f);
$oldJsStart = "<?php include '../templates/footer.php'; ?>\n<script>\nvar matriks=";
$pos = strpos($t, $oldJsStart);
if ($pos === false) { echo "not found oldJsStart\n"; exit; }
$newJs = <<<'JS'
<?php include '../templates/footer.php'; ?>
<script>
var matriks=<?=json_encode($matriks ?? [], JSON_UNESCAPED_UNICODE)?>;
function idxByProgram(k, prog){ var list=matriks[k]||matriks[String(k)]||[]; for(var i=0;i<list.length;i++) if((list[i].program||'').trim()===String(prog).trim()) return i; return -1; }
function syncMatriksAdd(){
 var k=$('#addModal select[name="komponen"]').val(); var prog=$('#add_program_sel').val();
 if(!k || !prog){ $('#add_kegiatan,#add_tujuan,#add_indikator,#add_target').val(''); $('#add_target_badge').text(''); $('#add_evaluasi,#add_tindak').val(''); return; }
 var idx=idxByProgram(k,prog); if(idx<0) return;
 var m=matriks[k][idx]||matriks[String(k)][idx];
 $('#add_kegiatan').val(m.kegiatan||'');
 $('#add_tujuan').val(m.tujuan||'');
 $('#add_indikator').val(m.indikator||''); $('#add_target').val(m.target||''); $('#add_target_badge').text(m.target||'');
 $('#add_evaluasi').val(m.evaluasi||'');
 $('#add_tindak').val(m.tindak||'');
}
function syncMatriksEdit(){
 var k=$('#edit_komponen').val(); var prog=$('#edit_program_sel').val();
 if(!k || !prog){ $('#edit_kegiatan,#edit_tujuan,#edit_indikator,#edit_target').val(''); $('#edit_target_badge').text(''); $('#edit_evaluasi,#edit_tl').val(''); return; }
 var idx=idxByProgram(k,prog); if(idx<0) return;
 var m=matriks[k][idx]||matriks[String(k)][idx];
 $('#edit_kegiatan').val(m.kegiatan||'');
 $('#edit_tujuan').val(m.tujuan||'');
 $('#edit_indikator').val(m.indikator||''); $('#edit_target').val(m.target||''); $('#edit_target_badge').text(m.target||'');
 $('#edit_evaluasi').val(m.evaluasi||'');
 $('#edit_tl').val(m.tindak||'');
}
function fillProgram(k, selId, cur){
 var $s=$(selId); $s.empty().append('<option value="">-- Pilih Program --</option>');
 var list=matriks[k]||matriks[String(k)]||[];
 list.forEach(function(r){ $s.append($('<option>').val(r.program).text(r.program)); });
 if(cur){
  if($s.find('option').filter(function(){return $(this).val()===cur;}).length===0) $s.append($('<option>').val(cur).text(cur));
  $s.val(cur);
 }
}
$(document).on('change','#addModal select[name="komponen"]',function(){ var k=$(this).val(); fillProgram(k,'#add_program_sel',''); $('#add_kegiatan,#add_tujuan,#add_indikator,#add_target,#add_evaluasi,#add_tindak').val(''); $('#add_target_badge').text(''); });
$(document).on('change','#edit_komponen',function(){ var k=$(this).val(); fillProgram(k,'#edit_program_sel',''); $('#edit_kegiatan,#edit_tujuan,#edit_indikator,#edit_target,#edit_evaluasi,#edit_tl').val(''); $('#edit_target_badge').text(''); });
$(document).on('change','#add_program_sel',syncMatriksAdd);
$(document).on('change','#edit_program_sel',syncMatriksEdit);
JS;
$oldEndMarker = "function fmtRupiah";
$pos2 = strpos($t, $oldEndMarker, $pos);
if ($pos2===false){ echo "oldEnd not found\n"; exit;}
$tail = substr($t, $pos2);
// tail starts with function fmtRupiah etc to end
$newTail = $newJs . "\n" . $tail;
// replace from $pos to end with newTail? Actually we need to keep tail after newJs header
$before = substr($t, 0, $pos);
$t2 = $before . $newTail;
file_put_contents($f, $t2);
echo "patched js\n";
