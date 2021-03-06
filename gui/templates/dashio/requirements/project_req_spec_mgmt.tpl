{*
TestLink Open Source Project - http://testlink.sourceforge.net/
@filesource project_req_spec_mgmt.tpl
*}
{* ------------------------------------------------------ *}
{$cfg_section=$smarty.template|basename|replace:".tpl":"" }
{config_load file="input_dimensions.conf" section=$cfg_section}

{lang_get var="labels" s="btn_reorder_req_spec,btn_new_req_spec,btn_import,btn_export_all_reqspec"}
{$req_module='lib/requirements/'}
{$url_args="reqSpecEdit.php?doAction=create&tproject_id="}
{$req_spec_new_url="$basehref$req_module$url_args"}

{$url_args="reqSpecEdit.php?doAction=reorder&tproject_id="}
{$req_spec_reorder_url="$basehref$req_module$url_args"}

{$url_args="reqExport.php?scope=tree&tproject_id="}
{$req_export_url="$basehref$req_module$url_args"}

{$url_args="reqImport.php?scope=tree&tproject_id="}
{$req_import_url="$basehref$req_module$url_args"}


{include file="inc_head.tpl"}

{* ------------------------------------------ *}
<body>
<h1 class="{#TITLE_CLASS#}">{$gui->main_descr|escape}</h1>
<div class="workBack">
  <div class="groupBtn">
    <form method="post">
      {if $gui->userGrants->modify}
      <input class="{#BUTTON_CLASS#}" type="button" 
             id="new_req_spec" name="new_req_spec"
             value="{$labels.btn_new_req_spec}"
             onclick="location='{$req_spec_new_url}{$gui->tproject_id}'" />
      {/if}

      <input class="{#BUTTON_CLASS#}" type="button" 
             id="export_all" name="export_all"
             value="{$labels.btn_export_all_reqspec}"
             onclick="location='{$req_export_url}{$gui->tproject_id}'" />

      {if $gui->userGrants->modify}
      <input class="{#BUTTON_CLASS#}" type="button" 
             id="import_all" name="import_all"
             value="{$labels.btn_import}"
             onclick="location='{$req_import_url}{$gui->tproject_id}'" />
      {/if}
    </form>
  </div>
</div>

{if $gui->refresh_tree == "yes"}
  {include file="inc_refreshTree.tpl"}
{/if}

</body>
</html>