<!-- GROUPS -->
<div id="groups" class="tab-pane fade in">

    {# ── Subscription import panel ───────────────────────────────────── #}
    <div class="row">
        <section class="col-xs-12">
            <div style="padding: 8px 15px; border-bottom: 1px solid #ddd;">
                <div class="form-inline" style="margin-bottom: 6px;">
                    <input type="text" id="subImportName" class="form-control input-sm"
                           style="width: 12em; margin-right: 4px;"
                           placeholder="{{ lang._('Group name') }}">
                    <input type="text" id="subImportUrl" class="form-control input-sm"
                           style="width: 28em; margin-right: 4px;"
                           placeholder="{{ lang._('Subscription URL (https://...)') }}">
                    <input type="text" id="subImportUa" class="form-control input-sm"
                           style="width: 14em; margin-right: 4px;"
                           value="v2rayNG/1.9.5"
                           placeholder="{{ lang._('User-Agent') }}">
                    <button id="btnSubImport" class="btn btn-sm btn-primary"
                            title="{{ lang._('Fetch the subscription and create a group') }}">
                        <i class="fa fa-download fa-fw"></i> {{ lang._('Import subscription') }}
                    </button>
                </div>
                <textarea id="subImportBody" class="form-control input-sm" rows="3"
                          placeholder="{{ lang._('or paste the subscription body / links here') }}"></textarea>
                <span id="subImportResult" style="font-size: 12px; display: inline-block; margin-top: 4px;"></span>
            </div>
        </section>
    </div>

    {# ── Groups grid ─────────────────────────────────────────────────── #}
    <div class="row">
        <section class="col-xs-12">
            <h4 style="margin-left: 15px;">{{ lang._('Groups') }}</h4>
            <table id="grid-groups"
                   class="table table-condensed table-hover table-striped"
                   data-editDialog="DialogGroup"
                   data-editAlert="GroupChangeMessage">
                <thead>
                    <tr>
                        <th data-column-id="uuid"
                            data-type="string"
                            data-identifier="true"
                            data-visible="false">{{ lang._('ID') }}</th>

                        <th data-column-id="name"
                            data-type="string">{{ lang._('Name') }}</th>

                        <th data-column-id="source"
                            data-type="string"
                            data-width="9em">{{ lang._('Source') }}</th>

                        <th data-column-id="servers"
                            data-type="string"
                            data-sortable="false"
                            data-width="7em">{{ lang._('Servers') }}</th>

                        <th data-column-id="last_fetch"
                            data-type="string"
                            data-width="13em">{{ lang._('Last Fetch') }}</th>

                        <th data-column-id="last_count"
                            data-type="string"
                            data-width="8em">{{ lang._('Nodes') }}</th>

                        <th data-column-id="commands"
                            data-formatter="groupCommands"
                            data-sortable="false"
                            data-width="10em">{{ lang._('') }}</th>
                    </tr>
                </thead>
                <tbody></tbody>
                <tfoot>
                    <tr>
                        <td></td>
                        <td>
                            <button data-action="add" type="button" class="btn btn-xs btn-primary">
                                <span class="fa fa-fw fa-plus"></span>
                            </button>
                            <button data-action="deleteSelected" type="button" class="btn btn-xs btn-default">
                                <span class="fa fa-fw fa-trash-o"></span>
                            </button>
                        </td>
                    </tr>
                </tfoot>
            </table>

            <div id="GroupChangeMessage" class="alert alert-info" style="display: none;" role="alert">
                {{ lang._('Group changes take effect for an instance when you select a server and click Apply.') }}
            </div>
        </section>
    </div>

    {# ── Servers grid ────────────────────────────────────────────────── #}
    <div class="row">
        <section class="col-xs-12">
            <h4 style="margin-left: 15px;">{{ lang._('Servers') }}</h4>
            <div class="form-inline" style="margin: 0 15px 6px 15px;">
                <label for="serverGroupFilter" style="margin-right: 4px;">{{ lang._('Group') }}</label>
                <select id="serverGroupFilter" class="form-control input-sm" style="width: 16em;">
                    <option value="">{{ lang._('All groups') }}</option>
                </select>
            </div>
            <table id="grid-servers"
                   class="table table-condensed table-hover table-striped"
                   data-editDialog="DialogServer"
                   data-editAlert="GroupChangeMessage">
                <thead>
                    <tr>
                        <th data-column-id="uuid"
                            data-type="string"
                            data-identifier="true"
                            data-visible="false">{{ lang._('ID') }}</th>

                        <th data-column-id="name"
                            data-type="string">{{ lang._('Name') }}</th>

                        <th data-column-id="address"
                            data-type="string">{{ lang._('Address') }}</th>

                        <th data-column-id="port"
                            data-type="string"
                            data-width="6em">{{ lang._('Port') }}</th>

                        <th data-column-id="stale"
                            data-formatter="serverStale"
                            data-sortable="false"
                            data-width="7em">{{ lang._('Stale') }}</th>

                        <th data-column-id="commands"
                            data-formatter="commands"
                            data-sortable="false"
                            data-width="8em">{{ lang._('') }}</th>
                    </tr>
                </thead>
                <tbody></tbody>
                <tfoot>
                    <tr>
                        <td></td>
                        <td>
                            <button data-action="add" type="button" class="btn btn-xs btn-primary">
                                <span class="fa fa-fw fa-plus"></span>
                            </button>
                            <button data-action="deleteSelected" type="button" class="btn btn-xs btn-default">
                                <span class="fa fa-fw fa-trash-o"></span>
                            </button>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </section>
    </div>

</div>
