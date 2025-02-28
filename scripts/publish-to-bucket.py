#! /usr/bin/env python3

import subprocess
import html

def list_zip_files():
    """
    List zip files in the Google Cloud Storage bucket
    """
    try:
        # Run gsutil command to list zip files
        result = subprocess.run(
            ['gsutil', 'ls', 'gs://achteraf-betalen/magento/**/*.zip'], 
            capture_output=True, 
            text=True, 
            check=True
        )
        # Extract filenames from full paths
        return ['/'.join(line.strip().split('/')[-2:]) for line in result.stdout.splitlines()]
    except subprocess.CalledProcessError as e:
        print(f"Error listing files: {e}")
        return []

def simple_template(template, **kwargs):
    """
    Simple string templating function without external dependencies
    """
    for key, value in kwargs.items():
        template = template.replace(f'{{{{ {key} }}}}', str(value))
    return template

def generate_index_html(zip_files):
    """
    Generate index.html content with links to zip files, using simple templating
    """
    html_template = """<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <title>{{ title }}</title>
    <style>
        body { 
            font-family: Arial, sans-serif; 
            max-width: 800px; 
            margin: 0 auto; 
            padding: 20px; 
            line-height: 1.6;
        }
        h1 { 
            color: #333; 
            border-bottom: 2px solid #0066cc;
            padding-bottom: 10px;
        }
        h2 {
            color: #666;
            font-size: 1.2em;
            margin-bottom: 20px;
        }
        .file-list { 
            list-style-type: none; 
            padding: 0; 
        }
        .file-item { 
            margin-bottom: 10px; 
        }
        .file-link { 
            color: #0066cc; 
            text-decoration: none; 
            display: inline-block; 
            padding: 10px; 
            background-color: #f4f4f4; 
            border-radius: 5px; 
            transition: background-color 0.3s ease;
        }
        .file-link:hover { 
            background-color: #e6e6e6; 
            text-decoration: underline; 
        }
        .file-icon { 
            margin-right: 10px; 
            text-decoration: none; 
        }
    </style>
</head>
<body>
    <h1>{{ title }}</h1>
    <h2>{{ subheading }}</h2>
    <ul class='file-list'>
        {{ file_links }}
    </ul>
</body>
</html>"""

    # Generate file links
    file_links = []
    for zip_file in sorted(zip_files, reverse=True):
        # Escape HTML to prevent XSS
        safe_filename = html.escape(zip_file)
        file_link = (f"        <li class='file-item'>"
                     f"<span class='file-icon'>📦</span>"
                     f"<a href='{safe_filename}' class='file-link'>{safe_filename}</a></li>")
        file_links.append(file_link)

    # Apply templating
    html_content = simple_template(
        html_template, 
        title='Achteraf betalen van ABN AMRO',
        subheading='Available Magento Plugins',
        file_links='\n'.join(file_links)
    )

    return html_content

def upload_index_html(html_content):
    """
    Upload generated index.html to the Google Cloud Storage bucket
    """
    try:
        # Create a temporary file
        with open('index.html', 'w') as f:
            f.write(html_content)
        
        # Upload to GCS
        subprocess.run(
            ['gsutil', '-h', 'Content-Type:text/html', 
             'cp', 'index.html', 'gs://achteraf-betalen/magento/index.html'], 
            check=True
        )
        print("index.html successfully uploaded to GCS")
    except subprocess.CalledProcessError as e:
        print(f"Error uploading index.html: {e}")
    except Exception as e:
        print(f"Unexpected error: {e}")

def main():
    # List zip files
    zip_files = list_zip_files()
    
    # Generate HTML
    html_content = generate_index_html(zip_files)
    
    # Upload HTML
    upload_index_html(html_content)

if __name__ == '__main__':
    main()
