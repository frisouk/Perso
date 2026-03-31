#!/usr/bin/env python3
import argparse
import html
import json
import posixpath
import re
import sys
import zipfile
from collections import Counter, defaultdict
from datetime import datetime, timedelta
from pathlib import Path
from xml.etree import ElementTree as ET


MAIN_NS = "http://schemas.openxmlformats.org/spreadsheetml/2006/main"
REL_NS = "http://schemas.openxmlformats.org/officeDocument/2006/relationships"
COL_RE = re.compile(r"([A-Z]+)(\d+)")
DEFAULT_INPUT = Path("/home/ubuntu/RVTools_export_all_2026-02-26_11.16.27.xlsx")


def col_to_idx(col_name):
    value = 0
    for char in col_name:
        value = value * 26 + ord(char) - 64
    return value - 1


def shared_text(node):
    return "".join(text.text or "" for text in node.iter(f"{{{MAIN_NS}}}t"))


def parse_float(value):
    try:
        return float(value)
    except (TypeError, ValueError):
        return 0.0


def parse_excel_date(value):
    try:
        return datetime(1899, 12, 30) + timedelta(days=float(value))
    except (TypeError, ValueError):
        return None


def format_number(value, digits=0):
    if digits == 0:
        return f"{int(round(value)):,}".replace(",", " ")
    return f"{value:,.{digits}f}".replace(",", " ").replace(".", ",")


def fmt_gib(mib):
    return f"{format_number(mib / 1024, 1)} GiB"


def fmt_tib(mib):
    return f"{format_number(mib / 1024 / 1024, 2)} TiB"


def cli_args():
    parser = argparse.ArgumentParser(
        description="Genere un tableau de bord HTML a partir d'un export RVTools XLSX."
    )
    parser.add_argument(
        "input",
        nargs="?",
        default=str(DEFAULT_INPUT),
        help="Chemin du fichier RVTools .xlsx",
    )
    parser.add_argument(
        "-o",
        "--output",
        help="Chemin du fichier HTML genere. Defaut: <input>_dashboard.html",
    )
    parser.add_argument(
        "--title",
        default="Tableau de bord RVTools VMware",
        help="Titre principal du dashboard",
    )
    parser.add_argument(
        "--summary-output",
        help="Chemin optionnel d'un fichier JSON de synthese pour integration applicative",
    )
    return parser.parse_args()


def default_output_path(input_path):
    return input_path.with_name(f"{input_path.stem}_dashboard.html")


def get_rows(data, sheet_name, warnings, required=False):
    rows = data.get(sheet_name, [])
    if not rows and (required or sheet_name not in data):
        warnings.append(f"Onglet absent ou vide: {sheet_name}")
    return rows


def get_value(row, *columns, default=""):
    for column in columns:
        if column in row and row.get(column) not in (None, ""):
            return row.get(column)
    return default


def count_present(rows, *columns):
    return sum(1 for row in rows if any(row.get(column) not in (None, "") for column in columns))


def normalize_tools_label(label):
    value = (label or "").strip()
    if not value:
        return "unknown"
    mapping = {
        "guesttoolscurrent": "toolsOk",
        "guesttoolsneedupgrade": "toolsOld",
        "guesttoolsnotrunning": "toolsNotRunning",
        "guesttoolsnotinstalled": "toolsNotInstalled",
    }
    lowered = value.lower()
    return mapping.get(lowered, value)


def detect_os_family(label):
    lowered = (label or "").lower()
    if "microsoft" in lowered or "windows" in lowered:
        return "state-blue"
    if "red hat" in lowered or "rhel" in lowered:
        return "state-red"
    if any(token in lowered for token in ["debian", "ubuntu", "suse", "linux", "photon", "centos", "oracle linux", "rocky", "alma"]):
        return "state-green"
    return "state-black"


def build_summary(data, generated_at=None):
    generated_at = generated_at or datetime.now()
    warnings = []
    vinfo = get_rows(data, "vInfo", warnings)
    vpartition = get_rows(data, "vPartition", warnings)
    vnetwork = get_rows(data, "vNetwork", warnings)
    vsource = get_rows(data, "vSource", warnings)
    vmeta = get_rows(data, "vMetaData", warnings)

    total_vms = len(vinfo)
    total_vcpu = sum(parse_float(get_value(row, "CPUs", "# vCPUs")) for row in vinfo)
    total_ram_gib = sum(
        parse_float(get_value(row, "Memory", "Size MiB", "Mem Configured")) for row in vinfo
    ) / 1024
    total_disk_gib = sum(
        parse_float(get_value(row, "Total disk capacity MiB", "Provisioned MiB")) for row in vinfo
    ) / 1024

    if total_vms == 0 and vpartition:
        total_vms = len(
            {
                get_value(row, "VM", "Name")
                for row in vpartition
                if get_value(row, "VM", "Name")
            }
        )

    vm_os = {}
    for row in vpartition:
        vm = get_value(row, "VM", "Name")
        if vm and vm not in vm_os:
            vm_os[vm] = get_value(
                row,
                "OS according to the VMware Tools",
                "OS according to the configuration file",
                "Guest OS",
                default="",
            )

    windows_vcpu = 0
    for row in vinfo:
        vm = get_value(row, "VM", "Name")
        os_name = vm_os.get(vm, "")
        if detect_os_family(os_name) == "state-blue":
            windows_vcpu += parse_float(get_value(row, "CPUs", "# vCPUs"))

    internet_bandwidth = 0
    for row in vnetwork:
        network_name = get_value(row, "Network", "Port Group", default="")
        if "internet" in network_name.lower():
            internet_bandwidth += 1

    source = vsource[0] if vsource else {}
    meta = vmeta[0] if vmeta else {}
    export_date = parse_excel_date(get_value(meta, "xlsx creation datetime"))

    return {
        "perimetre": {
            "vm": int(round(total_vms)),
            "vcpu": int(round(total_vcpu)),
            "vcpu_windows": int(round(windows_vcpu)),
            "vram": int(round(total_ram_gib)),
            "vdisk": int(round(total_disk_gib)),
            "transit": int(round(internet_bandwidth)),
            "project_name": "",
        },
        "meta": {
            "rvtools_version": get_value(meta, "RVTools version"),
            "source": get_value(source, "Fullname", "Product name", default="VMware vSphere"),
            "server": get_value(meta, "Server", default=""),
            "generated_at": generated_at.isoformat(),
            "exported_at": export_date.isoformat() if export_date else "",
        },
    }


def validate_columns(rows, sheet_name, required_aliases, warnings):
    if not rows:
        return
    first_row = rows[0]
    for aliases in required_aliases:
        if not any(alias in first_row for alias in aliases):
            warnings.append(
                f"Colonne absente dans {sheet_name}: {', '.join(aliases)}"
            )


def read_workbook(path):
    with zipfile.ZipFile(path) as archive:
        shared_strings = []
        if "xl/sharedStrings.xml" in archive.namelist():
            sst = ET.fromstring(archive.read("xl/sharedStrings.xml"))
            shared_strings = [shared_text(item) for item in sst]

        workbook = ET.fromstring(archive.read("xl/workbook.xml"))
        rels = ET.fromstring(archive.read("xl/_rels/workbook.xml.rels"))
        rel_map = {item.attrib["Id"]: item.attrib["Target"] for item in rels}

        sheets = {}
        for sheet in workbook.find(f"{{{MAIN_NS}}}sheets"):
            rel_id = sheet.attrib[f"{{{REL_NS}}}id"]
            target = rel_map[rel_id]
            if target.startswith("/"):
                target = target[1:]
            elif not target.startswith("xl/"):
                target = posixpath.normpath(posixpath.join("xl", target))
            sheets[sheet.attrib["name"]] = target

        data = {}
        for sheet_name, target in sheets.items():
            root = ET.fromstring(archive.read(target))
            header = None
            rows = []
            for row in root.iter(f"{{{MAIN_NS}}}row"):
                values = {}
                for cell in row.iter(f"{{{MAIN_NS}}}c"):
                    ref = cell.attrib.get("r", "A1")
                    match = COL_RE.match(ref)
                    index = col_to_idx(match.group(1))
                    cell_type = cell.attrib.get("t")
                    value_node = cell.find(f"{{{MAIN_NS}}}v")
                    inline_node = cell.find(f"{{{MAIN_NS}}}is")
                    if cell_type == "s" and value_node is not None:
                        values[index] = shared_strings[int(value_node.text)]
                    elif cell_type == "inlineStr" and inline_node is not None:
                        values[index] = "".join(
                            text.text or "" for text in inline_node.iter(f"{{{MAIN_NS}}}t")
                        )
                    elif value_node is not None:
                        values[index] = value_node.text or ""
                    else:
                        values[index] = ""

                if not values:
                    continue

                width = max(values) + 1
                row_values = [values.get(idx, "") for idx in range(width)]
                if header is None:
                    header = row_values
                    continue
                if len(row_values) < len(header):
                    row_values.extend([""] * (len(header) - len(row_values)))
                rows.append(dict(zip(header, row_values)))
            data[sheet_name] = rows
        return data


def unique_map(rows, key_field, value_field):
    mapping = {}
    for row in rows:
        key = row.get(key_field)
        if key and key not in mapping:
            mapping[key] = row.get(value_field, "")
    return mapping


def top_counter(counter, limit):
    return [{"label": key or "Non renseigne", "value": value} for key, value in counter.most_common(limit)]


def render_progress_rows(items, percent=False, tone="default"):
    if not items:
        return "<p class='empty'>Aucune donnee disponible.</p>"

    max_value = max(item["value"] for item in items) or 1
    rows = []
    for item in items:
        value = item["value"]
        width = min(max(value, 0), 100) if percent else (value / max_value) * 100
        label = html.escape(str(item["label"]))
        if percent:
            value_text = f"{value:.1f} %".replace(".", ",")
        else:
            value_text = format_number(value)
        color_class = ""
        if percent and tone == "threshold":
            if value < 30:
                color_class = " state-green"
            elif value < 50:
                color_class = " state-yellow"
            elif value < 70:
                color_class = " state-orange"
            else:
                color_class = " state-red"
        elif tone == "tools":
            lowered = str(item["label"]).lower()
            if lowered == "toolsok":
                color_class = " state-green"
            elif lowered == "toolsold":
                color_class = " state-orange"
            else:
                color_class = " state-black"
        elif tone == "os":
            color_class = f" {detect_os_family(item['label'])}"
        rows.append(
            f"<div class='metric-row'>"
            f"<div class='metric-head'><span>{label}</span><strong>{value_text}</strong></div>"
            f"<div class='bar {tone}{color_class}'><span style='width:{width:.1f}%'></span></div>"
            f"</div>"
        )
    return "".join(rows)


def render_alert_rows(items):
    if not items:
        return "<p class='empty'>Aucune alerte selectionnee.</p>"
    rows = []
    for item in items:
        rows.append(
            "<div class='alert-row'>"
            f"<strong>{html.escape(item['title'])}</strong>"
            f"<span>{html.escape(item['detail'])}</span>"
            "</div>"
        )
    return "".join(rows)


def render_signal_groups(groups):
    if not groups:
        return "<p class='empty'>Aucune alerte selectionnee.</p>"

    blocks = []
    for group in groups:
        items_html = []
        for item in group["items"]:
            items_html.append(
                "<div class='signal-card'>"
                f"<span class='signal-object'>{html.escape(item['object'])}</span>"
                f"<strong>{html.escape(item['name'])}</strong>"
                f"<span class='signal-kind'>{html.escape(item['kind'])}</span>"
                "</div>"
            )
        blocks.append(
            "<section class='signal-group'>"
            f"<div class='signal-group-head'><h3>{html.escape(group['title'])}</h3>"
            f"<span>{format_number(group['count'])}</span></div>"
            f"<div class='signal-grid'>{''.join(items_html)}</div>"
            "</section>"
        )
    return "".join(blocks)


def build_dashboard(data, title="Tableau de bord RVTools VMware", generated_at=None):
    generated_at = generated_at or datetime.now()
    warnings = []

    vinfo = get_rows(data, "vInfo", warnings, required=True)
    vhost = get_rows(data, "vHost", warnings)
    vdatastore = get_rows(data, "vDatastore", warnings)
    vsnapshot = get_rows(data, "vSnapshot", warnings)
    vtools = get_rows(data, "vTools", warnings)
    vhealth = get_rows(data, "vHealth", warnings)
    vnetwork = get_rows(data, "vNetwork", warnings)
    vpartition = get_rows(data, "vPartition", warnings)
    vcpu = get_rows(data, "vCPU", warnings)
    vmemory = get_rows(data, "vMemory", warnings)
    vsource = get_rows(data, "vSource", warnings)
    vmeta = get_rows(data, "vMetaData", warnings)

    validate_columns(vinfo, "vInfo", [("VM", "Name"), ("Powerstate",), ("CPUs", "# vCPUs"), ("Memory", "Size MiB", "Mem Configured")], warnings)
    validate_columns(vhost, "vHost", [("Host", "Name"), ("CPU usage %", "CPU overallUsage"), ("Memory usage %", "Mem usage %")], warnings)
    validate_columns(vdatastore, "vDatastore", [("Name",), ("Free %",), ("Free MiB", "Cluster free space MiB")], warnings)
    validate_columns(vtools, "vTools", [("VM", "Name"), ("Tools",), ("Upgradeable",)], warnings)
    validate_columns(vhealth, "vHealth", [("Name", "VM"), ("Message",), ("Message type",)], warnings)

    power_counts = Counter(get_value(row, "Powerstate", default="unknown") for row in vinfo if get_value(row, "VM", "Name"))
    total_vms = len(vinfo)
    total_hosts = len(vhost)
    total_vcpu = sum(parse_float(get_value(row, "CPUs", "# vCPUs")) for row in vinfo)
    total_ram_mib = sum(parse_float(get_value(row, "Memory", "Size MiB", "Mem Configured")) for row in vinfo)
    total_disk_mib = sum(parse_float(get_value(row, "Total disk capacity MiB", "Provisioned MiB")) for row in vinfo)
    total_partition_consumed_mib = sum(parse_float(get_value(row, "Consumed MiB")) for row in vpartition)
    total_datastore_used_mib = sum(parse_float(get_value(row, "In Use MiB")) for row in vdatastore)

    if total_vms == 0 and vpartition:
        total_vms = len({get_value(row, "VM", "Name") for row in vpartition if get_value(row, "VM", "Name")})
    if total_hosts == 0 and vdatastore:
        host_names = set()
        for row in vdatastore:
            hosts_value = get_value(row, "Hosts")
            if hosts_value:
                host_names.update(part.strip() for part in str(hosts_value).split(",") if part.strip())
        total_hosts = len(host_names)

    host_cpu_usage = [
        {"label": get_value(row, "Host", "Name", default="Host"), "value": parse_float(get_value(row, "CPU usage %", "CPU overallUsage"))}
        for row in vhost
    ]
    host_mem_usage = [
        {"label": get_value(row, "Host", "Name", default="Host"), "value": parse_float(get_value(row, "Memory usage %", "Mem usage %"))}
        for row in vhost
    ]

    datastore_capacity_mib = sum(parse_float(get_value(row, "Capacity MiB", "Cluster capacity MiB")) for row in vdatastore)
    datastore_free_mib = sum(parse_float(get_value(row, "Free MiB", "Cluster free space MiB")) for row in vdatastore)
    datastore_usage = []
    for row in sorted(vdatastore, key=lambda item: parse_float(get_value(item, "Free %"))):
        used_pct = max(0.0, 100.0 - parse_float(get_value(row, "Free %")))
        datastore_usage.append(
            {
                "label": get_value(row, "Name", default="Datastore"),
                "value": used_pct,
                "detail": (
                    f"{used_pct:.1f} % utilises, "
                    f"{fmt_tib(parse_float(get_value(row, 'Free MiB', 'Cluster free space MiB')))} libres"
                ).replace(".", ","),
            }
        )

    vm_host = unique_map(vpartition, "VM", "Host")
    host_vm_counts = Counter(vm_host.values())

    vm_os = {}
    for row in vpartition:
        vm = get_value(row, "VM", "Name")
        if vm and vm not in vm_os:
            vm_os[vm] = get_value(
                row,
                "OS according to the VMware Tools",
                "OS according to the configuration file",
                "Guest OS",
                default="Non renseigne",
            )
    os_distribution = top_counter(Counter(vm_os.values()), 8)
    microsoft_vcpu = 0
    for row in vinfo:
        vm = get_value(row, "VM", "Name")
        if detect_os_family(vm_os.get(vm, "")) == "state-blue":
            microsoft_vcpu += parse_float(get_value(row, "CPUs", "# vCPUs"))

    network_vms = defaultdict(set)
    for row in vnetwork:
        vm = get_value(row, "VM", "Name")
        network = get_value(row, "Network", "Port Group")
        if vm and network:
            network_vms[network].add(vm)
    network_distribution = [
        {"label": label, "value": len(vms)} for label, vms in sorted(network_vms.items(), key=lambda item: len(item[1]), reverse=True)[:8]
    ]

    health_types = top_counter(Counter(get_value(row, "Message type", default="Autre") for row in vhealth), 8)
    upgradeable_tools = sum(1 for row in vtools if get_value(row, "Upgradeable", default="No") == "Yes")
    tools_state = top_counter(Counter(normalize_tools_label(get_value(row, "Tools", default="unknown")) for row in vtools), 6)

    snapshot_items = []
    for row in vsnapshot:
        snap_date = parse_excel_date(get_value(row, "Date / time", "Date"))
        age_days = (generated_at - snap_date).days if snap_date else None
        snapshot_items.append(
            {
                "vm": get_value(row, "VM", "Name", default="VM"),
                "age_days": age_days or 0,
                "size_mib": parse_float(get_value(row, "Size MiB (total)", "Size MiB")),
                "name": get_value(row, "Name", default=""),
            }
        )
    snapshot_items.sort(key=lambda item: (item["age_days"], item["size_mib"]), reverse=True)

    top_cpu_vms = [
        {
            "title": get_value(row, "VM", "Name", default="VM"),
            "detail": f"{format_number(parse_float(get_value(row, 'Overall', 'CPU overallUsage')))} MHz consommes pour {get_value(row, 'CPUs', '# vCPUs', default='?')} vCPU",
        }
        for row in sorted(vcpu, key=lambda item: parse_float(get_value(item, "Overall", "CPU overallUsage")), reverse=True)[:5]
    ]
    top_mem_vms = [
        {
            "title": get_value(row, "VM", "Name", default="VM"),
            "detail": (
                f"{fmt_gib(parse_float(get_value(row, 'Active', 'Consumed')))} actifs sur "
                f"{fmt_gib(parse_float(get_value(row, 'Size MiB', 'Memory', 'Mem Configured')))} alloues"
            ),
        }
        for row in sorted(vmemory, key=lambda item: parse_float(get_value(item, "Active", "Consumed")), reverse=True)[:5]
    ]

    signal_groups = []

    def append_group(title, entries, limit=6):
        if not entries:
            return
        signal_groups.append(
            {
                "title": title,
                "count": len(entries),
                "items": entries[:limit],
            }
        )

    snapshot_entries = []
    for item in snapshot_items:
        if item["age_days"] > 7:
            snapshot_entries.append(
                {
                    "object": "Snapshot",
                    "name": item["vm"],
                    "kind": f"{item['age_days']} jours, {fmt_gib(item['size_mib'])}",
                }
            )
    append_group("Snapshots anciens", snapshot_entries, limit=3)
    storage_entries = []
    for row in datastore_usage:
        if row["value"] >= 80:
            storage_entries.append(
                {
                    "object": "Datastore",
                    "name": row["label"],
                    "kind": row["detail"],
                }
            )
    append_group("Stockage sous pression", storage_entries, limit=4)

    tools_issue_vms = []
    for row in vtools:
        vm = get_value(row, "VM", "Name", default="VM")
        tools_state_value = normalize_tools_label(get_value(row, "Tools", default=""))
        upgradeable = get_value(row, "Upgradeable", default="")
        if tools_state_value in {"toolsOld", "toolsNotRunning", "toolsNotInstalled"} or upgradeable == "Yes":
            reason = []
            if tools_state_value == "toolsOld":
                reason.append("Tools obsoletes")
            elif tools_state_value == "toolsNotRunning":
                reason.append("Tools arretes")
            elif tools_state_value == "toolsNotInstalled":
                reason.append("Tools non installes")
            if upgradeable == "Yes":
                reason.append("mise a jour disponible")
            tools_issue_vms.append({"object": "VM", "name": vm, "kind": ", ".join(reason)})
    append_group("VMware Tools", tools_issue_vms, limit=6)

    zombie_items = []
    folder_items = []
    security_items = []
    perf_items = []
    for row in vhealth:
        msg_type = get_value(row, "Message type", default="")
        name = get_value(row, "Name", "VM", default="")
        message = get_value(row, "Message", default="")
        if msg_type == "Zombie":
            zombie_items.append({"object": "Fichier / VM", "name": name, "kind": message})
        elif msg_type == "Foldername":
            folder_items.append({"object": "Dossier", "name": name, "kind": message})
        elif msg_type == "Security" and ("SSH" in message or "ESXi Shell" in message):
            security_items.append({"object": "Hote / service", "name": name, "kind": message})
        elif msg_type == "Performance tip" and ("Disk" in message or "In-Memory" in message):
            perf_items.append({"object": "VM", "name": name, "kind": message})

    append_group("Zombies VMDK / VM", zombie_items, limit=6)

    oversized_vcpu = []
    for row in vcpu:
        vcpu_count = parse_float(get_value(row, "CPUs", "# vCPUs"))
        if vcpu_count > 16:
            oversized_vcpu.append(
                {
                    "object": "VM",
                    "name": get_value(row, "VM", "Name", default="VM"),
                    "kind": f"{format_number(vcpu_count)} vCPU alloues",
                }
            )
    append_group("vCPU > 16", oversized_vcpu, limit=6)
    append_group("Securite ESXi Shell / SSH", security_items, limit=6)
    append_group("Incoherences de dossiers", folder_items, limit=6)
    append_group("Performance stockage / memoire", perf_items, limit=6)

    if not signal_groups:
        signal_groups.append(
            {
                "title": "Alerte critique",
                "count": 0,
                "items": [{"object": "Analyse", "name": "Aucun signal fort", "kind": "Aucune alerte selectionnee"}],
            }
        )

    source = vsource[0] if vsource else {}
    meta = vmeta[0] if vmeta else {}
    export_date = parse_excel_date(get_value(meta, "xlsx creation datetime"))
    export_text = export_date.strftime("%d/%m/%Y %H:%M") if export_date else "Non renseignee"

    donut_values = {
        "poweredOn": power_counts.get("poweredOn", 0),
        "poweredOff": power_counts.get("poweredOff", 0),
        "suspended": power_counts.get("suspended", 0),
        "other": max(total_vms - power_counts.get("poweredOn", 0) - power_counts.get("poweredOff", 0) - power_counts.get("suspended", 0), 0),
    }
    donut_total = max(sum(donut_values.values()), 1)
    on_pct = donut_values["poweredOn"] / donut_total * 100
    off_pct = donut_values["poweredOff"] / donut_total * 100
    susp_pct = donut_values["suspended"] / donut_total * 100
    other_pct = donut_values["other"] / donut_total * 100
    donut_style = (
        "conic-gradient("
        f"var(--ok) 0 {on_pct:.2f}%, "
        f"var(--ink) {on_pct:.2f}% {on_pct + off_pct:.2f}%, "
        f"var(--steel) {on_pct + off_pct:.2f}% {on_pct + off_pct + susp_pct:.2f}%, "
        f"var(--soft) {on_pct + off_pct + susp_pct:.2f}% 100%)"
    )

    host_cards = []
    for row in vhost:
        host_cards.append(
            {
                "name": get_value(row, "Host", "Name", default="Host"),
                "cpu": parse_float(get_value(row, "CPU usage %", "CPU overallUsage")),
                "mem": parse_float(get_value(row, "Memory usage %", "Mem usage %")),
                "vms": get_value(row, "# VMs", "# VMs total", default="0"),
            }
        )

    datastore_cards = []
    for row in datastore_usage[:6]:
        datastore_cards.append(
            "<div class='mini-card'>"
            f"<strong>{html.escape(row['label'])}</strong>"
            f"<span>{html.escape(row['detail'])}</span>"
            f"<div class='bar compact'><span style='width:{row['value']:.1f}%'></span></div>"
            "</div>"
        )

    datastore_progress = [{"label": row["label"], "value": row["value"]} for row in datastore_usage]
    host_vm_pills = "".join(
        f"<span class='pill'>{html.escape(item['label'])}: {format_number(item['value'])} VMs</span>"
        for item in top_counter(host_vm_counts, 4)
    )
    host_cards_html = "".join(
        f"<div class='host-card'><strong>{html.escape(card['name'])}</strong>"
        f"<p>CPU {str(round(card['cpu'], 1)).replace('.', ',')} %</p>"
        f"<p>RAM {str(round(card['mem'], 1)).replace('.', ',')} %</p>"
        f"<p>{html.escape(str(card['vms']))} VMs visibles sur l'hote</p></div>"
        for card in host_cards
    )

    page_title = title
    subtitle = (
        f"{html.escape(get_value(source, 'Fullname', 'Product name', default='VMware vSphere'))} | "
        f"Export RVTools {html.escape(get_value(meta, 'RVTools version', default=''))} | "
        f"{html.escape(export_text)}"
    )

    summary = (
        f"La plateforme exportee contient {format_number(total_vms)} VMs, {format_number(total_hosts)} hotes "
        f"et {format_number(len(vdatastore))} datastores. La capacite visible represente "
        f"{fmt_tib(total_disk_mib)} de disques presentes cote VM et {fmt_tib(datastore_capacity_mib)} "
        f"de capacite datastore, avec {fmt_tib(datastore_free_mib)} encore libres."
    )
    data_coverage = (
        f"Onglets detectes: {format_number(len(data))}. "
        f"vInfo={format_number(len(vinfo))}, vHost={format_number(len(vhost))}, vDatastore={format_number(len(vdatastore))}, "
        f"vTools={format_number(len(vtools))}, vHealth={format_number(len(vhealth))}."
    )
    warnings_html = ""
    if warnings:
        warning_items = "".join(f"<li>{html.escape(item)}</li>" for item in sorted(set(warnings)))
        warnings_html = (
            "<article class='panel span-12'>"
            "<h2>Couverture de l'export</h2>"
            f"<p class='section-note'>{html.escape(data_coverage)}</p>"
            f"<ul class='warning-list'>{warning_items}</ul>"
            "</article>"
        )

    html_doc = f"""<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{page_title}</title>
  <style>
    :root {{
      --bg: #f2f2f2;
      --panel: #ffffff;
      --panel-strong: #fcfcfc;
      --ink: #151515;
      --muted: #5b5b5b;
      --line: #d8d8d8;
      --accent: #c1121f;
      --accent-soft: #fff9f9;
      --ok: #2f855a;
      --steel: #7b7b7b;
      --soft: #d8d8d8;
      --shadow: 0 18px 36px rgba(0, 0, 0, 0.08);
      --radius: 22px;
    }}
    * {{ box-sizing: border-box; }}
    body {{
      margin: 0;
      font-family: "Avenir Next", "Gill Sans", "Trebuchet MS", sans-serif;
      color: var(--ink);
      background:
        linear-gradient(180deg, #f7f7f7 0%, #f2f2f2 45%, #ededed 100%);
    }}
    .shell {{
      width: min(1280px, calc(100% - 32px));
      margin: 24px auto 48px;
    }}
    .hero {{
      padding: 28px;
      border: 1px solid var(--line);
      border-radius: calc(var(--radius) + 8px);
      background: linear-gradient(180deg, #ffffff 0%, #fcfcfc 100%);
      box-shadow: var(--shadow);
      position: relative;
      overflow: hidden;
    }}
    .hero::before {{
      content: "";
      position: absolute;
      inset: 0 auto 0 0;
      width: 8px;
      background: var(--accent);
    }}
    .eyebrow {{
      margin: 0 0 10px 14px;
      font-size: 12px;
      letter-spacing: 0.16em;
      text-transform: uppercase;
      color: var(--accent);
      font-weight: 700;
    }}
    h1 {{
      margin: 0 0 0 14px;
      font-size: clamp(30px, 4vw, 56px);
      line-height: 1;
    }}
    .subtitle {{
      margin: 12px 0 0 14px;
      color: var(--muted);
      font-size: 15px;
    }}
    .summary {{
      margin: 18px 0 0 14px;
      max-width: 900px;
      color: var(--ink);
      line-height: 1.6;
    }}
    .grid {{
      display: grid;
      grid-template-columns: repeat(12, 1fr);
      gap: 18px;
      margin-top: 18px;
    }}
    .panel {{
      background: var(--panel);
      border: 1px solid var(--line);
      border-radius: var(--radius);
      padding: 22px;
      box-shadow: var(--shadow);
    }}
    .span-12 {{ grid-column: span 12; }}
    .span-8 {{ grid-column: span 8; }}
    .span-6 {{ grid-column: span 6; }}
    .span-4 {{ grid-column: span 4; }}
    .span-3 {{ grid-column: span 3; }}
    .metrics {{
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 14px;
      margin-top: 22px;
    }}
    .metric-card {{
      padding: 18px;
      border-radius: 18px;
      background: linear-gradient(180deg, #ffffff 0%, #fcfcfc 100%);
      border: 1px solid var(--line);
      border-top: 3px solid var(--accent);
    }}
    .metric-card span {{
      display: block;
      color: var(--muted);
      font-size: 13px;
      margin-bottom: 8px;
    }}
    .metric-card strong {{
      display: block;
      font-size: clamp(24px, 3vw, 40px);
    }}
    h2 {{
      margin: 0 0 16px;
      font-size: 20px;
      color: var(--accent);
    }}
    .section-note {{
      margin: -8px 0 18px;
      color: var(--muted);
      font-size: 14px;
    }}
    .donut-wrap {{
      display: flex;
      align-items: center;
      gap: 24px;
      min-height: 260px;
    }}
    .donut {{
      width: 220px;
      aspect-ratio: 1;
      border-radius: 50%;
      background: {donut_style};
      position: relative;
      flex: 0 0 auto;
    }}
    .donut::after {{
      content: "";
      position: absolute;
      inset: 28px;
      border-radius: 50%;
      background: var(--panel);
      box-shadow: inset 0 0 0 1px var(--line);
    }}
    .donut-center {{
      position: absolute;
      inset: 0;
      display: grid;
      place-items: center;
      text-align: center;
      z-index: 1;
      font-weight: 700;
    }}
    .donut-center small {{
      display: block;
      font-size: 12px;
      color: var(--muted);
      font-weight: 500;
    }}
    .legend {{
      display: grid;
      gap: 10px;
      flex: 1 1 auto;
    }}
    .legend-item {{
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 14px;
      padding: 12px 14px;
      border-radius: 14px;
      background: #fff;
      border: 1px solid var(--line);
    }}
    .legend-item em {{
      display: inline-flex;
      width: 12px;
      height: 12px;
      border-radius: 999px;
      margin-right: 10px;
      vertical-align: middle;
    }}
    .metric-row + .metric-row,
    .alert-row + .alert-row {{
      margin-top: 14px;
    }}
    .metric-head {{
      display: flex;
      justify-content: space-between;
      gap: 12px;
      margin-bottom: 8px;
      font-size: 14px;
    }}
    .bar {{
      height: 12px;
      border-radius: 999px;
      background: #efefef;
      overflow: hidden;
    }}
    .bar.compact {{
      height: 8px;
      margin-top: 10px;
    }}
    .bar span {{
      display: block;
      height: 100%;
      border-radius: inherit;
      background: linear-gradient(90deg, var(--ink) 0%, var(--accent) 100%);
    }}
    .bar.red span {{
      background: linear-gradient(90deg, #7f0c15 0%, var(--accent) 100%);
    }}
    .bar.threshold.state-green span {{
      background: linear-gradient(90deg, #2f855a 0%, #48bb78 100%);
    }}
    .bar.threshold.state-yellow span {{
      background: linear-gradient(90deg, #b7791f 0%, #ecc94b 100%);
    }}
    .bar.threshold.state-orange span {{
      background: linear-gradient(90deg, #c05621 0%, #ed8936 100%);
    }}
    .bar.threshold.state-red span {{
      background: linear-gradient(90deg, #7f0c15 0%, var(--accent) 100%);
    }}
    .bar.tools.state-green span {{
      background: linear-gradient(90deg, #2f855a 0%, #48bb78 100%);
    }}
    .bar.tools.state-orange span {{
      background: linear-gradient(90deg, #c05621 0%, #ed8936 100%);
    }}
    .bar.tools.state-black span {{
      background: linear-gradient(90deg, #151515 0%, #4a4a4a 100%);
    }}
    .bar.os.state-blue span {{
      background: linear-gradient(90deg, #1e5aa8 0%, #4299e1 100%);
    }}
    .bar.os.state-red span {{
      background: linear-gradient(90deg, #9b1c1c 0%, #e53e3e 100%);
    }}
    .bar.os.state-green span {{
      background: linear-gradient(90deg, #2f855a 0%, #48bb78 100%);
    }}
    .bar.os.state-black span {{
      background: linear-gradient(90deg, #151515 0%, #4a4a4a 100%);
    }}
    .alert-row {{
      padding: 14px 16px;
      border-radius: 16px;
      border: 1px solid var(--line);
      background: var(--accent-soft);
      border-left: 4px solid var(--accent);
    }}
    .alert-row strong,
    .mini-card strong {{
      display: block;
      margin-bottom: 6px;
    }}
    .alert-row span,
    .mini-card span {{
      color: var(--muted);
      font-size: 14px;
      line-height: 1.5;
    }}
    .mini-grid {{
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: 14px;
    }}
    .mini-card {{
      padding: 16px;
      border-radius: 16px;
      border: 1px solid var(--line);
      background: #fff;
    }}
    .host-grid {{
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: 14px;
    }}
    .host-card {{
      padding: 16px;
      border-radius: 16px;
      background: linear-gradient(180deg, #ffffff, #fcfcfc);
      border: 1px solid var(--line);
      border-left: 4px solid var(--accent);
    }}
    .host-card p {{
      margin: 8px 0 0;
      color: var(--muted);
      font-size: 14px;
    }}
    .pill-row {{
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      margin-top: 16px;
    }}
    .pill {{
      padding: 10px 12px;
      border-radius: 999px;
      background: #fff;
      border: 1px solid var(--line);
      font-size: 13px;
    }}
    .empty {{
      color: var(--muted);
      margin: 0;
    }}
    .warning-list {{
      margin: 0;
      padding-left: 18px;
      color: var(--muted);
    }}
    .warning-list li + li {{
      margin-top: 8px;
    }}
    .signal-group + .signal-group {{
      margin-top: 18px;
    }}
    .signal-group-head {{
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      margin-bottom: 12px;
    }}
    .signal-group-head h3 {{
      margin: 0;
      font-size: 16px;
      color: var(--ink);
    }}
    .signal-group-head span {{
      min-width: 36px;
      padding: 6px 10px;
      border-radius: 999px;
      background: var(--accent-soft);
      border: 1px solid var(--line);
      color: var(--accent);
      font-size: 12px;
      font-weight: 700;
      text-align: center;
    }}
    .signal-grid {{
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: 10px;
    }}
    .signal-card {{
      padding: 12px 14px;
      border: 1px solid var(--line);
      border-left: 4px solid var(--accent);
      border-radius: 14px;
      background: #fff;
      min-width: 0;
    }}
    .signal-object {{
      display: block;
      margin-bottom: 4px;
      color: var(--muted);
      font-size: 11px;
      letter-spacing: 0.08em;
      text-transform: uppercase;
    }}
    .signal-card strong {{
      display: block;
      margin-bottom: 6px;
      font-size: 14px;
      overflow-wrap: anywhere;
    }}
    .signal-kind {{
      display: block;
      color: var(--muted);
      font-size: 13px;
      line-height: 1.4;
      overflow-wrap: anywhere;
    }}
    @media (max-width: 1100px) {{
      .span-8, .span-6, .span-4, .span-3 {{ grid-column: span 12; }}
      .metrics {{ grid-template-columns: repeat(2, 1fr); }}
    }}
    @media (max-width: 720px) {{
      .shell {{ width: min(100% - 20px, 1280px); }}
      .hero, .panel {{ padding: 18px; }}
      .metrics, .mini-grid, .host-grid, .signal-grid {{ grid-template-columns: 1fr; }}
      .donut-wrap {{ flex-direction: column; align-items: flex-start; }}
      .donut {{ width: 180px; }}
    }}
  </style>
</head>
<body>
  <main class="shell">
    <section class="hero">
      <p class="eyebrow">Analyse RVTools</p>
      <h1>{page_title}</h1>
      <p class="subtitle">{subtitle}</p>
      <p class="summary">{html.escape(summary)}</p>
      <div class="metrics">
        <div class="metric-card"><span>Machines virtuelles</span><strong>{format_number(total_vms)}</strong></div>
        <div class="metric-card"><span>Hotes ESXi</span><strong>{format_number(total_hosts)}</strong></div>
        <div class="metric-card"><span>vCPU alloues</span><strong>{format_number(total_vcpu)}</strong></div>
        <div class="metric-card"><span>vCPU Microsoft</span><strong>{format_number(microsoft_vcpu)}</strong></div>
        <div class="metric-card"><span>RAM allouee</span><strong>{fmt_gib(total_ram_mib)}</strong></div>
        <div class="metric-card"><span>Disque VM provisionne</span><strong>{fmt_tib(total_disk_mib)}</strong></div>
        <div class="metric-card"><span>Disque consomme dans les VMs</span><strong>{fmt_tib(total_partition_consumed_mib)}</strong></div>
        <div class="metric-card"><span>Disque utilise sur datastores</span><strong>{fmt_tib(total_datastore_used_mib)}</strong></div>
        <div class="metric-card"><span>Datastores</span><strong>{format_number(len(vdatastore))}</strong></div>
        <div class="metric-card"><span>VMware Tools a mettre a jour</span><strong>{format_number(upgradeable_tools)}</strong></div>
      </div>
    </section>

    <section class="grid">
      {warnings_html}
      <article class="panel span-4">
        <h2>Etat des VMs</h2>
        <p class="section-note">Repartition issue de l'onglet <code>vInfo</code>.</p>
        <div class="donut-wrap">
          <div class="donut">
            <div class="donut-center">
              <div>{format_number(total_vms)}<small>VMs visibles</small></div>
            </div>
          </div>
          <div class="legend">
            <div class="legend-item"><span><em style="background:var(--ok)"></em>Allumees</span><strong>{format_number(donut_values['poweredOn'])}</strong></div>
            <div class="legend-item"><span><em style="background:var(--ink)"></em>Eteintes</span><strong>{format_number(donut_values['poweredOff'])}</strong></div>
            <div class="legend-item"><span><em style="background:var(--steel)"></em>Suspendues</span><strong>{format_number(donut_values['suspended'])}</strong></div>
            <div class="legend-item"><span><em style="background:var(--soft)"></em>Autres / templates</span><strong>{format_number(donut_values['other'])}</strong></div>
          </div>
        </div>
      </article>

      <article class="panel span-4">
        <h2>Utilisation des hotes</h2>
        <p class="section-note">Charge instantanee CPU et RAM d'apres <code>vHost</code>.</p>
        <h3>CPU</h3>
        {render_progress_rows(host_cpu_usage, percent=True, tone="threshold")}
        <h3 style="margin-top:18px;">RAM</h3>
        {render_progress_rows(host_mem_usage, percent=True, tone="threshold")}
        <div class="pill-row">
          {host_vm_pills}
        </div>
      </article>

      <article class="panel span-4">
        <h2>Pression stockage</h2>
        <p class="section-note">Occupation des datastores visibles dans l'export.</p>
        {render_progress_rows(datastore_progress, percent=True, tone="threshold")}
      </article>

      <article class="panel span-6">
        <h2>Signaux operationnels</h2>
        <p class="section-note">Points a surveiller en priorite.</p>
        {render_signal_groups(signal_groups)}
      </article>

      <article class="panel span-6">
        <h2>Composition de l'inventaire</h2>
        <p class="section-note">OS invites les plus frequents et reseaux les plus utilises.</p>
        <div class="mini-grid">
          <div>
            <h3>OS majoritaires</h3>
            {render_progress_rows(os_distribution, percent=False, tone="os")}
          </div>
          <div>
            <h3>Reseaux par nombre de VMs</h3>
            {render_progress_rows(network_distribution, percent=False)}
          </div>
        </div>
      </article>

      <article class="panel span-6">
        <h2>Etat des VMware Tools</h2>
        <p class="section-note">Synthese des statuts remontes dans <code>vTools</code>.</p>
        {render_progress_rows(tools_state, percent=False, tone="tools")}
      </article>

      <article class="panel span-6">
        <h2>Familles d'alertes RVTools</h2>
        <p class="section-note">Volume d'alertes par categorie dans <code>vHealth</code>.</p>
        {render_progress_rows(health_types, percent=False)}
      </article>

      <article class="panel span-6">
        <h2>VMs les plus sollicitees</h2>
        <p class="section-note">Top consommation CPU et memoire active.</p>
        <div class="mini-grid">
          <div>{render_alert_rows(top_cpu_vms)}</div>
          <div>{render_alert_rows(top_mem_vms)}</div>
        </div>
      </article>

      <article class="panel span-6">
        <h2>Vue hotes et datastores</h2>
        <p class="section-note">Lecture rapide de la densite et du stockage principal.</p>
        <div class="host-grid">
          {host_cards_html}
        </div>
        <div class="mini-grid" style="margin-top:14px;">
          {''.join(datastore_cards)}
        </div>
      </article>
    </section>
  </main>
</body>
</html>
"""
    return html_doc


def main():
    args = cli_args()
    input_path = Path(args.input).expanduser().resolve()
    output_path = Path(args.output).expanduser().resolve() if args.output else default_output_path(input_path)

    if not input_path.exists():
        print(f"Erreur: fichier introuvable: {input_path}", file=sys.stderr)
        raise SystemExit(1)
    if input_path.suffix.lower() != ".xlsx":
        print(f"Erreur: le fichier source doit etre un .xlsx RVTools: {input_path}", file=sys.stderr)
        raise SystemExit(1)

    try:
        data = read_workbook(input_path)
    except zipfile.BadZipFile:
        print(f"Erreur: fichier Excel invalide ou corrompu: {input_path}", file=sys.stderr)
        raise SystemExit(1)
    except KeyError as exc:
        print(f"Erreur: structure XLSX incomplete, element manquant: {exc}", file=sys.stderr)
        raise SystemExit(1)

    generated_at = datetime.now()
    dashboard = build_dashboard(data, title=args.title, generated_at=generated_at)
    output_path.write_text(dashboard, encoding="utf-8")
    if args.summary_output:
        summary_path = Path(args.summary_output).expanduser().resolve()
        summary = build_summary(data, generated_at=generated_at)
        summary_path.write_text(
            json.dumps(summary, ensure_ascii=False, indent=2),
            encoding="utf-8",
        )
    print(f"Dashboard generated: {output_path}")


if __name__ == "__main__":
    main()
