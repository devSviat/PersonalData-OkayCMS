{* Вкладки — окремі контролери зі своїми правами; посилання на вкладку без права не показується. *}
<nav class="tab_navigation tab_navigation--round sviat-pd-tabs" aria-label="{$btr->sviat__personal_data__title|escape}">
    {if $manager->permissions && in_array('sviat__personal_data', $manager->permissions)}
        <a class="tab_navigation_link{if $sviat_pd_tab == 'forms'} selected{/if}" href="{url controller='Sviat.PersonalData.DescriptionAdmin'}"{if $sviat_pd_tab == 'forms'} aria-current="page"{/if}>{$btr->sviat__personal_data__tab_forms|escape}</a>
    {/if}
    {if $manager->permissions && in_array('sviat__personal_data__log', $manager->permissions)}
        <a class="tab_navigation_link{if $sviat_pd_tab == 'log'} selected{/if}" href="{url controller='Sviat.PersonalData.ConsentLogAdmin'}"{if $sviat_pd_tab == 'log'} aria-current="page"{/if}>{$btr->sviat__personal_data__tab_log|escape}</a>
    {/if}
</nav>
