import imp
import os
import sys

# Add user site-packages to path so Passenger can find installed packages
sys.path.insert(0, os.path.expanduser('~/.local/lib/python3.10/site-packages'))
sys.path.insert(0, os.path.dirname(__file__))

wsgi = imp.load_source('wsgi', 'app.py')
application = wsgi.app